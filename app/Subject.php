<?php

namespace App;

use App\Repository\SubjectRepository;
use App\Scopes\TenantTrait;
use App\Stats\StatableInterface;
use App\Stats\StatableTrait;
use ArieTimmerman\Laravel\AuthChain\Object\Eloquent\Subject as EloquentSubject;
use Idaas\OpenID\Entities\ClaimEntityInterface;
use Laravel\Passport\HasApiTokens;

class Subject extends EloquentSubject implements SubjectInterface, StatableInterface
{
    use HasApiTokens;
    use TenantTrait;
    use StatableTrait;

    protected $user = null;
    protected $userId = null;

    protected $roles = null;

    public function getUser()
    {
        if ($this->user == null) {
            $this->user = User::find($this->getUserId());
        }

        return $this->user;
    }

    public function user()
    {
        return $this->belongsTo('App\User');
    }

    public function moduleResults()
    {
        return $this->hasMany(ModuleResult::class, 'subject_id');
    }

    // public function token(){
    //     return $this->belongsTo('App\User');
    // }

    // public function token(){
    //     return $this->belongsTo('App\User');
    // }

    public function getUserId()
    {
        if ($this->user_id == null) {
            $subject = $this->getSubject();
            $this->user_id = $subject->getUserId();
        }

        return $this->user_id;
    }

    //TODO: Introduce something like hasRole('admin'), to protect admin apis
    public function hasRole($role)
    {
        return in_array($role, $this->getRoles());
    }

    /**
     * @var ClaimEntityInterface[] $attributes
     */
    public function toIDTokenClaims($attributes, $scopes)
    {
        $user = $this->getUser();

        $hasEssential = collect($attributes)->contains(function ($value, $key) {
            return $value->getEssential();
        });

        $result = [
            'sub'       => $this->id, //return the identifier
        ];

        if (!$hasEssential) {
            $result['scim_id'] = $user ? $user->id : null;
        }

        //TODO: Wat if no user ...? Assume subject-attributes are userinfo-claims!
        foreach ($attributes as $attribute) {
            $attribute = $attribute->getIdentifier();

            switch ($attribute) {
                case 'name':
                    $result[$attribute] = $user->displayName ?? $user->name ?? $user->email;
                    break;
                case 'family_name':
                    $result[$attribute] = $user->familyName;
                    break;
                case 'given_name':
                    $result[$attribute] = $user->givenName;
                    break;
                case 'middle_name':
                    $result[$attribute] = $user->middleName;
                    break;
                case 'nickname':
                    //username
                    $result[$attribute] = $user->name;
                    break;
                case 'preferred_username':
                    //username
                    $result[$attribute] = $user->name;
                    break;
                case 'profile':
                    $result[$attribute] = route('ice.manage.profile', ['user_id' => $user->id]);
                    break;
                case 'picture':
                    $result[$attribute] = $user->picture;
                    break;
                case 'website':
                    //TODO: should contain website of user attribute
                    $result[$attribute] = url('/');
                    break;
                case 'preferred_username':
                    //username
                    $result[$attribute] = $user->name;
                    break;
                case 'gender':
                    //username
                    $result[$attribute] = $user->gender;
                    break;
                case 'birthdate':
                    //username
                    $result[$attribute] = $user->birthDate;
                    break;
                case 'zoneinfo':
                    //username
                    $result[$attribute] = $user->timezone;
                    break;
                case 'locale':
                    //TODO: Perhaps not entirly correct. SCIM has locale and preferredLanguage
                    $result[$attribute] = $user->preferredLanguage;
                    break;
                case 'updated_at':
                    $result[$attribute] = $user->updated_at->getTimestamp();
                    break;
                case 'roles':
                    $result[$attribute] = $this->getRoles();
                    break;
                case 'email':
                    $result[$attribute] = $user->email;
                    break;
                case 'email_verified':
                    $result[$attribute] = $user->email ? true : false;
                    break;
                case 'phone_number':
                    $result[$attribute] = $user->phoneNumber;
                    break;
                case 'address':
                    if (!empty($user->address)) {
                        $result[$attribute] = [
                            'formatted' => $user->address
                        ];
                    }
                    break;
                case 'phone_number_verified':
                    $result[$attribute] = false;
                    break;
                default:
                    break;
            }
        }

        return $result;
    }

    public function getRoles()
    {
        if ($this->roles != null) {
            return $this->roles;
        }

        $this->roles = [];

        $user = $this->getUser();

        if ($user != null) {
            $this->roles = $user->roles->pluck('id');
        } elseif ($this->getSubject() != null) {
            $this->roles = $this->getSubject()->getRoles();
        }

        // Prefer an empty array over null
        return $this->roles ?? [];
    }

    public function toUserInfo($claims, $scopes)
    {
        $attributes = [];
        $hasEssential = false;
        if (isset($claims['userinfo'])) {
            foreach ($claims['userinfo'] as $key => $value) {
                $attributes[] = $key;
                if (!empty($value) && isset($value['essential']) && $value['essential']) {
                    $hasEssential = true;
                }
            }
        }

        $attributesOriginal = $attributes;

        $result = $this->toIDTokenClaims($attributes, $scopes);

        $user = $this->getUser();
        $subject = $this->getSubject();

        if (config('serverless.openwhisk_enabled') && ($cloudFunction = CloudFunction::where('is_sequence', true)->where('type', CloudFunction::TYPE_ATTRIBUTE)->first()) != null) {

            $cloudResult = CloudFunctionHelper::invoke(
                $cloudFunction,
                [
                    'subject' => $this->subject,
                    'context' => [
                        'attributes' => $attributes,
                        'scopes' => $scopes
                    ]
                ]
            );

            $results = $cloudResult['results'] ?? [];

            $attributes = array_merge(
                ...collect($cloudResult['results'])->map(
                    function ($value) {
                        return $value['attributes'] ?? [];
                    }
                )->all()
            );

            $result = $result + $attributes;
        }

        $result['acr'] = $this->levels;

        $provider = OpenIDProvider::first();

        if ($user != null) {
            $result = $result + [
                'picture'   => $user ? $user->picture : null,
                'profile'      => $provider->getProfileURL($user->id),
                'roles' => $this->getRoles()
            ];
        } else {
            $result = array_merge($result, $subject->getAttributes());
        }

        // If one of the claims is "essential", only returns these claims and "sub". "sub" must always be returned.
        if ($hasEssential) {
            $result = collect($result)->filter(function ($value, $key) use ($attributesOriginal) {
                return in_array($key, array_merge($attributesOriginal, ['sub']));
            })->toArray();
        }
        return $result;
    }

    public function stats()
    {
        return $this->morphMany('App\Stat', 'statable');
    }

    public function findForPassport($identifier)
    {
        return resolve(SubjectRepository::class)->get($identifier);
    }
}
