<?php namespace Dingo\Api;

use Exception;
use Dingo\Api\Http\Response;
use Dingo\Api\Routing\Router;
use Illuminate\Routing\Route;
use Illuminate\Auth\AuthManager;
use Dingo\Api\Http\InternalRequest;
use Dingo\Api\Auth\ProviderInterface;
use Dingo\Api\Auth\AuthorizationProvider;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class Authentication {

	/**
	 * API router instance.
	 * 
	 * @var \Dingo\Api\Routing\Router
	 */
	protected $router;

	/**
	 * Illuminate auth instance.
	 * 
	 * @var \Illuminate\Auth\AuthManager
	 */
	protected $auth;

	/**
	 * Array of authentication providers.
	 * 
	 * @var array
	 */
	protected $providers;

	/**
	 * Authenticated user ID.
	 * 
	 * @var int
	 */
	protected $userId;

	/**
	 * Authenticated user instance.
	 * 
	 * @var \Illuminate\Auth\GenericUser|\Illuminate\Database\Eloquent\Model
	 */
	protected $user;

    /**
     * Create a new Dingo\Api\Authentication instance.
     * 
     * @param  \Dingo\Api\Routing\Router  $router
     * @param  \Illuminate\Auth\AuthManager  $auth
     * @param  array  $providers
     * @return void
     */
	public function __construct(Router $router, AuthManager $auth, array $providers)
	{
		$this->router = $router;
		$this->auth = $auth;
		$this->providers = $providers;
	}

	/**
	 * Authenticate the current request.
	 * 
	 * @return null|\Dingo\Api\Http\Response
	 */
	public function authenticate()
	{
		$request = $this->router->getCurrentRequest();

		if ($request instanceof InternalRequest or ! is_null($this->user))
		{
			return null;
		}

		if (  ! $route = $this->router->getCurrentRoute() or ! $this->routeIsProtected($route))
		{
			return null;
		}

		$exceptionStack = [];

		$this->registerOAuth2Scopes($route);

		// Spin through each of the registered authentication providers and attempt to
		// authenticate through one of them.
		foreach ($this->providers as $provider)
		{
			try
			{
				return $this->userId = $provider->authenticate($request);
			}
			catch (UnauthorizedHttpException $exception)
			{
				$exceptionStack[] = $exception;
			}
			catch (Exception $exception)
			{
				// We won't add this exception to the stack as it's thrown when the provider
				// is unable to authenticate due to the correct authorization header not
				// being set. We will throw an exception for this below.
			}
		}

		$exception = array_shift($exceptionStack);

		if ($exception === null)
		{
			$exception = new UnauthorizedHttpException(null, 'Failed to authenticate because of bad credentials or an invalid authorization header.');
		}

		throw $exception;
	}

	/**
	 * Register the OAuth 2.0 scopes on the "oauth2" provider.
	 * 
	 * @param  \Illuminate\Routing\Route  $route
	 * @return void
	 */
	protected function registerOAuth2Scopes(Route $route)
	{
		// If authenticating via OAuth2 a route can be protected by defining its scopes.
		// We'll grab the scopes for this route and pass them through to the
		// authentication providers.
		if (isset($this->providers['oauth2']))
		{
			$action = $route->getAction();

			$scopes = isset($action['scopes']) ? (array) $action['scopes'] : [];

			$this->providers['oauth2']->setScopes($scopes);
		}
	}

	/**
	 * Determine if a route is protected.
	 * 
	 * @param  \Illuminate\Routing\Route  $route
	 * @return bool
	 */
	protected function routeIsProtected(Route $route)
	{
		$action = $route->getAction();

		return in_array('protected', $action, true) or (isset($action['protected']) and $action['protected'] === true);
	}

	/**
	 * Get the authenticated user.
	 * 
	 * @return \Illuminate\Auth\GenericUser|\Illuminate\Database\Eloquent\Model
	 */
	public function getUser()
	{
		if ($this->user)
		{
			return $this->user;
		}

		if ( ! $this->auth->check())
		{
			$this->auth->onceUsingId($this->userId);
		}

		return $this->user = $this->auth->user();
	}

	/**
	 * Alias for getUser.
	 * 
	 * @return \Illuminate\Auth\GenericUser|\Illuminate\Database\Eloquent\Model
	 */
	public function user()
	{
		return $this->getUser();
	}

	/**
	 * Set the authenticated user.
	 * 
	 * @param  \Illuminate\Auth\GenericUser|\Illuminate\Database\Eloquent\Model  $user
	 * @return \Dingo\Api\Authentication
	 */
	public function setUser($user)
	{
		$this->user = $user;

		return $this;
	}

	/**
	 * Extend the authentication layer by registering a custom provider.
	 * 
	 * @param  string  $key
	 * @param  \Dingo\Api\Auth\ProviderInterface  $provider
	 * @return \Dingo\Api\Authentication
	 */
	public function extend($key, ProviderInterface $provider)
	{
		$this->providers[$key] = $provider;

		return $this;
	}

}