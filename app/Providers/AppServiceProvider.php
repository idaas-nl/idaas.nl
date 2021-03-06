<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repository\ModuleRepository;
use App\Repository\ChainRepository;
use App\Repository\SubjectRepository;
use App\Repository\LinkRepository;
use App\Repository\UserRepository;
use App\Repository\RemoteServiceProviderConfigRepository;
use App\Repository\HostedIdentityProviderConfigRepository;
use App\Http\AuthChainCompleteProcessor;
use App\Repository\AuthLevelRepository;
use Illuminate\Support\Facades\Log;
use function GuzzleHttp\json_encode;
use Illuminate\Support\Facades\Session;
use ArieTimmerman\Laravel\AuthChain\AuthChain;
use App\AuthTypes\TOTP;
use App\Scim\PolicyDecisionPoint;
use App\Session\DatabaseSessionHandler;
use Illuminate\Database\ConnectionInterface;
use App\AuthTypes\OpenIDConnect;
use App\AuthTypes\OtpMail;
use App\ResourceServerCustom;
use League\OAuth2\Server\ResourceServer;

// TODO: waarschijnlijk nodig bij registratie
// use ArieTimmerman\Passport\Bridge\AccessTokenRepository;
// use ArieTimmerman\Passport\OIDC\AdvancedBearerTokenValidator;

use App\Http\Controllers\AuthChain\StateStorage;
use App\SCIMConfig;
use App\SAMLConfig;
use App\Tenant;
use App\Exceptions\NoTenantException;
use Laravel\Passport\Token;
use App\Observers\TokenObserver;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\AuthChain\RememberStorage;
use Illuminate\Support\Facades\View;
use App\Http\Controllers\Controller;
use App\PassportConfig;
use App\Repository\ClaimRepository;
use App\Repository\KeyRepository;
use App\Repository\OIDCUserRepository;
use App\Repository\ProviderRepository;
use App\Repository\RefreshTokenRepository;
use App\Repository\TokenRepository;
use App\Session\OIDCSession;
use App\TokenCache;
use Idaas\OpenID\Repositories\UserRepositoryInterface;
use Idaas\Passport\Bridge\ClientRepository as BridgeClientRepository;
use Idaas\Passport\ClientRepository as IdaasClientRepository;
use Idaas\Passport\ProviderRepository as PassportProviderRepository;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(ConnectionInterface $connection)
    {

        Session::extend(
            'databaseWithCache',
            function ($app) use ($connection) {
                return new DatabaseSessionHandler($connection, $app['config']['session.table'], $app['config']['session.lifetime'], $app);
            }
        );

        if (config('app.env') == 'development') {
            \DB::listen(
                function ($sql) {
                    Log::debug(json_encode($sql));
                }
            );
        }

        $tenant = null;

        if (!app()->runningInConsole()) {
            if (preg_match('/^((?!manage).+?)\./', request()->getHttpHost(), $matches)) {

                $subdomain = $matches[1];

                $tenant = Cache::remember(
                    'tenant:' . $subdomain,
                    10,
                    function () use ($subdomain) {
                        return Tenant::where('subdomain', $subdomain)->first();
                    }
                );

                \App\Http\Middleware\DetectTenant::activateTenant($tenant);
            }

            if ($tenant != null) {
            } else {
                throw new NoTenantException('No tenant!');
            }
        }

        Token::observe(TokenObserver::class);

        //You must use singleton
        $this->app->singleton('ArieTimmerman\Laravel\AuthChain\Repository\ModuleRepositoryInterface', ModuleRepository::class);
        $this->app->singleton('ArieTimmerman\Laravel\AuthChain\Repository\ChainRepositoryInterface', ChainRepository::class);
        $this->app->singleton('ArieTimmerman\Laravel\AuthChain\Repository\SubjectRepositoryInterface', SubjectRepository::class);
        $this->app->singleton('ArieTimmerman\Laravel\AuthChain\Repository\LinkRepositoryInterface', LinkRepository::class);
        $this->app->singleton('ArieTimmerman\Laravel\AuthChain\Repository\UserRepositoryInterface', UserRepository::class);
        $this->app->singleton('ArieTimmerman\Laravel\AuthChain\Repository\AuthLevelRepository', AuthLevelRepository::class);
        $this->app->singleton('ArieTimmerman\Laravel\AuthChain\Http\CompleteProcessorInterface', AuthChainCompleteProcessor::class);
        $this->app->singleton(
            'ArieTimmerman\Laravel\SAML\Repository\RemoteServiceProviderConfigRepositoryInterface',
            RemoteServiceProviderConfigRepository::class
        );
        $this->app->singleton(
            'ArieTimmerman\Laravel\SAML\Repository\HostedIdentityProviderConfigRepositoryInterface',
            HostedIdentityProviderConfigRepository::class
        );

        $this->app->singleton(\Idaas\Passport\KeyRepository::class, KeyRepository::class);
        $this->app->singleton(\Idaas\Passport\PassportConfig::class, PassportConfig::class);
        $this->app->singleton(\Idaas\OpenID\Session::class, OIDCSession::class);

        $this->app->singleton(PassportProviderRepository::class, ProviderRepository::class);

        // $this->app->singleton(BridgeClientRepository::class, ClientRepository::class);


        $this->app->singleton('ArieTimmerman\Laravel\SCIMServer\PolicyDecisionPoint', PolicyDecisionPoint::class);
        $this->app->singleton('ArieTimmerman\Laravel\SCIMServer\SCIMConfig', SCIMConfig::class);
        $this->app->singleton('ArieTimmerman\Laravel\SAML\SAMLConfig', SAMLConfig::class);

        $this->app->singleton(
            \ArieTimmerman\Laravel\AuthChain\PolicyDecisionPoint::class,
            \App\Http\Controllers\AuthChain\PolicyDecisionPoint::class
        );
        $this->app->singleton(
            \ArieTimmerman\Laravel\AuthChain\Repository\ConsentRepository::class,
            \App\Repository\ConsentRepository::class
        );

        $this->app->singleton(UserRepositoryInterface::class, OIDCUserRepository::class);

        $this->app->singleton(\Idaas\Passport\TokenCache::class, TokenCache::class);
        $this->app->singleton(\Laravel\Passport\TokenRepository::class, TokenRepository::class);
        $this->app->singleton(\Idaas\Passport\Bridge\ClaimRepository::class, ClaimRepository::class);
        $this->app->singleton(\LAravel\Passport\Bridge\UserRepository::class, UserRepository::class);

        // TODO: delete AuthCodeRepository.php
        // $this->app->singleton(
        //     \Laravel\Passport\Bridge\AuthCodeRepository::class,
        //     \App\Repository\AuthCodeRepository::class
        // );

        // $this->app->singleton(
        //     \Laravel\Passport\Bridge\RefreshTokenRepository::class,
        //     RefreshTokenRepository::class
        // );

        $this->app->singleton(
            'ArieTimmerman\Laravel\AuthChain\StateStorage',
            StateStorage::class
        );
        $this->app->singleton(
            'ArieTimmerman\Laravel\AuthChain\RememberStorage',
            RememberStorage::class
        );

        // TODO: weer aanzetten
        // $this->app->singleton(
        //     ResourceServer::class,
        //     function () {
        //         return new ResourceServerCustom(
        //             $this->app->make(AccessTokenRepository::class),
        //             new AdvancedBearerTokenValidator($this->app->make(AccessTokenRepository::class))
        //         );
        //     }
        // );

        // allowed types
        AuthChain::addType(TOTP::class);
        AuthChain::addType(OpenIDConnect::class);
        AuthChain::addType('\App\AuthTypes\Passwordless');
        AuthChain::addType('\App\AuthTypes\Activation');
        AuthChain::addType('\App\AuthTypes\PasswordForgotten');
        AuthChain::addType('\App\AuthTypes\Register');
        AuthChain::addType(OtpMail::class);

        AuthChain::addType('\App\AuthTypes\Facebook');
        AuthChain::addType('\App\AuthTypes\Google');
        AuthChain::addType('\App\AuthTypes\Github');
        AuthChain::addType('\App\AuthTypes\Linkedin');
        AuthChain::addType('\App\AuthTypes\Twitter');

        View::composer(
            '*',
            function ($view) {
                $view->with('nonce', Controller::nonce());
            }
        );
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        Passport::ignoreMigrations();
    }
}
