<?php
/*
Allows self-service password reset.

Requires
- A mail address or username.
- Or a subject that is related to an user

(1) Ask for the users mail address (or get the one from the existing subject)
(2) Send a mail with a link with token.
(3) Let the user click on the link. Check token.
(4) Provide a prompt.
(5) Set the password of the user
*/

namespace App\AuthTypes;
use Illuminate\Http\Request;
use ArieTimmerman\Laravel\AuthChain\State;
use ArieTimmerman\Laravel\AuthChain\Module\ModuleResult;
use ArieTimmerman\Laravel\AuthChain\Module\ModuleInterface;
use ArieTimmerman\Laravel\AuthChain\Repository\SubjectRepositoryInterface;
use Illuminate\Support\Facades\Hash;

use Illuminate\Support\Facades\Mail;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Signer\Key;
use App\Repository\KeyRepository;
use ArieTimmerman\Laravel\AuthChain\Types\AbstractType;
use ArieTimmerman\Laravel\AuthChain\Helper;
use App\User;
use ArieTimmerman\Laravel\AuthChain\Object\Subject;
use ArieTimmerman\Laravel\AuthChain\Repository\UserRepositoryInterface;
use App\Exceptions\TokenExpiredException;
use App\EmailTemplate;
use App\Mail\StandardMail;

class PasswordForgotten extends AbstractType
{

    /**
     * This module can work as a first-factor, or as a second-factor in case the subject has a mail address
     */
    public function isEnabled(?Subject $subject)
    {
        return $subject == null || $subject->getEmail('email') != null;
    }

    public function getDefaultName()
    {
        return "Password Forgotten";
    }

    public function processCallback(Request $request)
    {
        
        // 1. get token
        $publicKey = resolve(KeyRepository::class)->getPublicKey();

        $parser = new Parser();
        $token = $parser->parse($request->query->get('token'));

        // 2. check signature
        $token->verify(new Sha256(), new Key($publicKey->getKeyPath()));

        // 3. check issuer, audience, expiration
        if($token->isExpired()) {
            throw new TokenExpiredException('password reset token expired');
        }

         // 4. get user with id equals subject
        $user = User::findOrFail($token->getClaim('sub'));

        // 5. check if last_login_date is the same from the jwt
        $state = Helper::loadStateFromSession(app(), $token->getClaim('state'));

        if($state == null) {
            throw new \Exception("Unknown state");
        }

        if($state->getIncomplete() == null) {
            throw new \Exception("Token already in use");
        }

        $module = $state->getIncomplete()->getModule();

        // 6. log the user in
        $result = $module->baseResult()->setCompleted(false)->setModuleState(['state'=>'confirmed'])->setSubject(resolve(SubjectRepositoryInterface::class)->with($user->email, $this, $module)->setTypeIdentifier($this->getIdentifier())->setUserId($user->id));

        $state->addResult($result);

        return Helper::getAuthResponseAsRedirect($request, $state);
    }

    public function getRedirect(ModuleInterface $module, State $state)
    {
        $state = (string)$state;
    }

    public function sendPasswordForgottenMail(Subject $subject, ModuleInterface $module, State $state)
    {
        
        Mail::to($subject->getEmail())->send(
            new StandardMail(
                @$module->config['template_id'], [
                'url'=> htmlentities(route('ice.login.passwordforgotten') . '?token=' . urlencode(self::getToken($subject->getUserId(), $state))),
                'subject' => $subject,
                'user' =>  $subject->getUser()
                ], EmailTemplate::TYPE_FORGOTTEN, $subject->getPreferredLanguage()
            )
        );

    }

    public function process(Request $request, State $state, ModuleInterface $module)
    {

        if($state->getIncomplete() != null && $state->getIncomplete()->moduleState != null && $state->getIncomplete()->moduleState['state'] == 'confirmed') {

            if($request->input('password')) {

                $subject = $state->getIncomplete()->getSubject();
                $user = $subject->getUser();
                
                $user->password = Hash::make($request->input('password'));
                $user->save();

                return $state->getIncomplete()->setCompleted(true)->setResponse(response([  ]));
            }else{
                return $state->getIncomplete()->setResponse(response([ 'error' => 'You must provide a password'  ], 400));
            }

        } else if($state->getSubject() != null) {

            $subject = $state->getSubject();

            if($state->getSubject()->getEmail() == null) {
                return (new ModuleResult())->setCompleted(false)->setResponse(response(['error'=>'No email address is known for this user']));
            }

            if($state->getSubject()->getUserId() == null) {
                return (new ModuleResult())->setCompleted(false)->setResponse(response(['error'=>'No user id is known for this user']));
            }

            $this->sendPasswordForgottenMail($subject, $module, $state);

            return $module->baseResult()->setCompleted(false)->setResponse(response([]));

        }else{

            $user = resolve(UserRepositoryInterface::class)->findByIdentifier($request->input('username'));

            if($user == null) {
                return (new ModuleResult())->setCompleted(false)->setResponse(response(['error'=>'User is not found'], 422));
            }

            $url = route('ice.login.passwordforgotten') . '?token=' . urlencode(self::getToken($user->id, $state));

            $subject = resolve(SubjectRepositoryInterface::class)->with($request->input('username'), $this, $module);
            $subject->setUserId($user->id);

            $this->sendPasswordForgottenMail($subject, $module, $state);
                    
            return $module->baseResult()->setSubject($subject)->setCompleted(false)->setResponse(response([]));

        }

    }

    public static function getToken($identifier, State $state)
    {

        $privateKey = resolve(KeyRepository::class)->getPrivateKey();

        return (new Builder())->setHeader('kid', $privateKey->getKid())
            ->setIssuer(url('/'))
            ->setSubject($identifier)
            ->setAudience(url('/'))
            ->setExpiration((new \DateTime('+300 seconds'))->getTimestamp())
            ->setIssuedAt((new \DateTime())->getTimestamp())
            ->set('state', (string) $state)
            //->set('last_login_date', $user->last_login_date)
            ->sign(new Sha256(), new Key($privateKey->getKeyPath(), $privateKey->getPassPhrase()))
            ->getToken();

    }


}