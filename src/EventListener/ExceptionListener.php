<?php

namespace Bolt\EventListener;

use Bolt\Config;
use Bolt\Exception\BootException;
use Bolt\Exception\Configuration\Validation\Database\AbstractDatabaseValidationException;
use Bolt\Exception\Configuration\Validation\Database\DatabaseParameterException;
use Bolt\Exception\Configuration\Validation\Database\MissingDatabaseExtensionException;
use Bolt\Exception\Configuration\Validation\Database\SqlitePathException;
use Bolt\Exception\Configuration\Validation\System\AbstractSystemValidationException;
use Bolt\Exception\Configuration\Validation\System\CacheValidationException;
use Bolt\Exception\Content\ContentExceptionInterface;
use Bolt\Exception\Content\FieldTwigRenderException;
use Bolt\Exception\Database\DatabaseConnectionException;
use Bolt\Exception\Database\DatabaseExceptionInterface;
use Bolt\Filesystem\Exception\IOException;
use Bolt\Filesystem\Handler\DirectoryInterface;
use Bolt\Helpers\Html;
use Bolt\Request\ProfilerAwareTrait;
use Bolt\Users;
use Carbon\Carbon;
use Cocur\Slugify\SlugifyInterface;
use Silex\Application;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig_Sandbox_SecurityError as SecurityError;
use Twig_Sandbox_SecurityNotAllowedFilterError as SecurityFilterError;
use Twig_Sandbox_SecurityNotAllowedFunctionError as SecurityFunctionError;
use Twig_Sandbox_SecurityNotAllowedMethodError as SecurityMethodError;
use Twig_Sandbox_SecurityNotAllowedPropertyError as SecurityPropertyError;
use Twig_Sandbox_SecurityNotAllowedTagError as SecurityTagError;

/**
 * HTTP kernel exception routing listener.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 * @author Carson Full <carsonfull@gmail.com>
 */
class ExceptionListener implements EventSubscriberInterface
{
    use ProfilerAwareTrait;

    /** @var \Twig_Environment */
    protected $twig;
    /** @var string */
    protected $rootPath;
    /** @var DirectoryInterface */
    protected $saveDir;
    /** @var SlugifyInterface */
    protected $slugifer;
    /** @var bool */
    protected $debug;
    /** @var Config */
    protected $config;
    /** @var Users */
    protected $users;
    /** @var SessionInterface */
    protected $session;
    /** @var RequestStack */
    protected $requestStack;

    /**
     * Constructor.
     *
     * @param \Twig_Environment  $twig
     * @param string             $rootPath
     * @param DirectoryInterface $saveDir
     * @param SlugifyInterface   $slugifer
     * @param bool               $debug
     * @param Config             $config
     * @param Users              $users
     * @param SessionInterface   $session
     * @param RequestStack       $requestStack
     */
    public function __construct(
        \Twig_Environment $twig,
        $rootPath,
        DirectoryInterface $saveDir,
        SlugifyInterface $slugifer,
        $debug,
        Config $config,
        Users $users,
        SessionInterface $session,
        RequestStack $requestStack
    ) {
        $this->twig = $twig;
        $this->rootPath = $rootPath;
        $this->saveDir = $saveDir;
        $this->slugifer = $slugifer;
        $this->debug = $debug;
        $this->config = $config;
        $this->users = $users;
        $this->session = $session;
        $this->requestStack = $requestStack;
    }

    /**
     * Handle boot initialisation exceptions.
     *
     * @param GetResponseForExceptionEvent $event
     */
    public function onBootException(GetResponseForExceptionEvent $event)
    {
        if ($this->isProfilerRequest($event->getRequest())) {
            return;
        }

        $exception = $event->getException();
        if ($exception instanceof BootException) {
            $event->setResponse($exception->getResponse());
        }
    }

    /**
     * Handle errors thrown in the application.
     *
     * @param GetResponseForExceptionEvent $event
     */
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        if ($this->isProfilerRequest($event->getRequest())) {
            return;
        }

        $exception = $event->getException();

        $html = null;
        if ($exception instanceof DatabaseExceptionInterface) {
            $html = $this->renderDatabaseException($exception);
        } elseif ($exception instanceof AbstractSystemValidationException) {
            $html = $this->renderSystemValidationException($exception);
        } elseif ($exception instanceof ContentExceptionInterface) {
            $html = $this->renderContentException($exception);
        }

        // If exception didn't match above or they didn't handle the exception, use the generic template.
        if (!$html) {
            $html = $this->renderException($exception);
        }

        $statusCode = $exception instanceof HttpExceptionInterface ?
            $exception->getStatusCode() :
            Response::HTTP_INTERNAL_SERVER_ERROR;
        $response = new Response($html, $statusCode, ['X-Debug-Exception-Handled' => time()]);

        $event->setResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::EXCEPTION => [
                ['onBootException', Application::EARLY_EVENT],
                ['onKernelException', -8],
            ],
        ];
    }

    protected function renderException(\Exception $exception)
    {
        $context = $this->getContext($exception);
        $context['type'] = 'general';
        $context['message'] = $exception->getMessage();

        return $this->twig->render('@bolt/exception/general.twig', $context);
    }

    protected function renderDatabaseException(DatabaseExceptionInterface $exception)
    {
        $context = $this->getContext($exception);

        $context['driver'] = $exception->getDriver();
        $context['name'] = $exception->getPlatform();

        if ($exception instanceof DatabaseConnectionException) {
            $context += [
                'type'     => 'connect',
                'platform' => $exception->getPlatform(),
            ];
        } elseif ($exception instanceof SqlitePathException) {
            $context += [
                'type'    => 'path',
                'subtype' => $exception->getType(),
                'path'    => $exception->getPath(),
                'error'   => $exception->getError(),
            ];
        } elseif ($exception instanceof AbstractDatabaseValidationException) {
            $context += [
                'type'    => 'driver',
                'subtype' => $exception->getSubType(),
            ];
            if ($exception instanceof DatabaseParameterException) {
                $context['parameter'] = $exception->getParameter();
            }
        } elseif ($exception instanceof MissingDatabaseExtensionException) {
            $context += [
                'type'    => 'driver',
                'subtype' => 'missing',
            ];
        }

        return $this->twig->render('@bolt/exception/database/exception.twig', $context);
    }

    protected function renderSystemValidationException(AbstractSystemValidationException $exception)
    {
        $context = $this->getContext($exception);

        $context['type'] = $exception->getType();

        if ($exception instanceof CacheValidationException) {
            $context['path'] = $exception->getPath();
        }

        return $this->twig->render('@bolt/exception/system/exception.twig', $context);
    }

    protected function renderContentException(ContentExceptionInterface $exception)
    {
        // Only FieldTwigRenderException's are handled right now
        if (!$exception instanceof FieldTwigRenderException) {
            return null;
        }

        $template = '@bolt/exception/content/field/twig_error.twig';
        $context = $this->getContext($exception);
        $context['field'] = $exception->getFieldName();

        // TODO show source snippet
        // $source = $exception->getSource();

        $twigError = $exception->getTwigError();

        if ($twigError instanceof SecurityError) {
            $template = '@bolt/exception/content/field/twig_security_error.twig';

            if ($twigError instanceof SecurityTagError) {
                $context['type'] = 'tag';
                $context['tag'] = $twigError->getTagName();
            } elseif ($twigError instanceof SecurityFilterError) {
                $context['type'] = 'filter';
                $context['filter'] = $twigError->getFilterName();
            } elseif ($twigError instanceof SecurityFunctionError) {
                $context['type'] = 'function';
                $context['function'] = $twigError->getFunctionName();
            } elseif ($twigError instanceof SecurityMethodError) {
                $context['type'] = 'method';
                $context['class'] = $twigError->getClassName();
                $context['method'] = $twigError->getMethodName();
            } elseif ($twigError instanceof SecurityPropertyError) {
                $context['type'] = 'property';
                $context['class'] = $twigError->getClassName();
                $context['property'] = $twigError->getPropertyName();
            }
        }

        return $this->twig->render($template, $context);
    }

    /**
     * Get a pre-packaged Twig context array.
     *
     * @param \Exception $exception
     *
     * @return array
     */
    protected function getContext(\Exception $exception = null)
    {
        if ($exception) {
            $this->saveException($exception);
        }

        $loggedOnUser = (bool) $this->users->getCurrentUser() ?: false;
        $showLoggedOff = (bool) $this->config->get('general/debug_show_loggedoff', false);

        // Note: We set this to a high value deliberately. If 'config' is not yet available, the
        // user can't influence this, so it shouldn't be too low.
        $traceLimit = (int) $this->config->get('general/debug_trace_argument_limit', 40);

        // Grab a section of the file that threw the exception, so we can show it.
        $filePath = $exception ? $exception->getFile() : null;
        $lineNumber = $exception ? $exception->getLine() : null;

        if ($filePath && $lineNumber) {
            $phpFile = file($filePath) ?: [];
            $snippet = implode('', array_slice($phpFile, max(0, $lineNumber - 6), 11));
        } else {
            $snippet = false;
        }

        // We might or might not have $this->app['request'] yet, which is used in the
        // template to show the request variables. Use it, or grab what we can get.
        $request = $this->requestStack->getCurrentRequest() ?: Request::createFromGlobals();

        return [
            'debug'     => ($this->debug && ($loggedOnUser || $showLoggedOff)),
            'request'   => $request,
            'exception' => [
                'object'      => $exception,
                'class_name'  => $exception ? (new \ReflectionClass($exception))->getShortName() : null,
                'class_fqn'   => $exception ? get_class($exception) : null,
                'file_path'   => $filePath,
                'file_name'   => basename($filePath),
                'trace'       => $exception ? $this->getSafeTrace($exception) : null,
                'snippet'     => $snippet,
                'trace_limit' => $traceLimit,
            ],
        ];
    }

    /**
     * Get an exception trace that is safe to display publicly.
     *
     * @param \Exception $exception
     *
     * @return array
     */
    protected function getSafeTrace(\Exception $exception)
    {
        if (!$this->debug && !($this->session->isStarted() && $this->session->has('authentication'))) {
            return [];
        }

        $trace = $exception->getTrace();
        foreach ($trace as $key => $value) {
            $trace[$key]['args_safe'] = $this->getSafeArguments($trace[$key]['args']);

            // Don't display the full path, trim 64-char hexadecimal file names.
            if (isset($trace[$key]['file'])) {
                $trace[$key]['file'] = str_replace($this->rootPath, '[root]', $trace[$key]['file']);
                $trace[$key]['file'] = preg_replace('/([0-9a-f]{16})[0-9a-f]{48}/i', '\1…', $trace[$key]['file']);
            }
        }

        return $trace;
    }

    /**
     * Get an array of safe (sanitised) function arguments from a trace entry.
     *
     * @param array $args
     *
     * @return array
     */
    protected function getSafeArguments(array $args)
    {
        $argsSafe = [];
        foreach ($args as $arg) {
            $type = gettype($arg);
            switch ($type) {
                case 'string':
                    $argsSafe[] = sprintf('<span>"%s"</span>', Html::trimText($arg, 30));
                    break;

                case 'integer':
                case 'float':
                    $argsSafe[] = sprintf('<span>%s</span>', $arg);
                    break;

                case 'object':
                    $className = get_class($arg);
                    $shortName = (new \ReflectionClass($arg))->getShortName();
                    $argsSafe[] = sprintf('<abbr title="%s">%s</abbr>', $className, $shortName);
                    break;

                case 'boolean':
                    $argsSafe[] = $arg ? '[true]' : '[false]';
                    break;

                default:
                    $argsSafe[] = '[' . $type . ']';
            }
        }

        return $argsSafe;
    }

    /**
     * Attempt to save the serialised exception if in debug mode.
     *
     * @param \Exception $exception
     */
    protected function saveException(\Exception $exception)
    {
        if ($this->debug !== true) {
            return;
        }

        $serialised = serialize(FlattenException::create($exception));

        $sourceFile = str_replace($this->rootPath, '', $exception->getFile());
        $sourceFile = substr($sourceFile, -102);
        $sourceFile = $this->slugifer->slugify($sourceFile);
        $fileName = sprintf('%s-%s.exception', Carbon::now()->format('Ymd-Hmi'), $sourceFile);

        try {
            $this->saveDir->getFile($fileName)->write($serialised);
        } catch (IOException $e) {
            // meh we tried
        }
    }
}
