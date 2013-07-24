<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Debug;

use Psr\Log\LoggerInterface;
use Symfony\Component\Debug\Exception\ClassNotFoundException;
use Symfony\Component\Debug\Exception\ContextErrorException;
use Symfony\Component\Debug\Exception\FatalErrorException;
use Symfony\Component\Debug\Exception\UndefinedFunctionException;
use Composer\Autoload\ClassLoader as ComposerClassLoader;
use Symfony\Component\ClassLoader as SymfonyClassLoader;
use Symfony\Component\ClassLoader\DebugClassLoader;

/**
 * ErrorHandler.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Konstantin Myakshin <koc-dp@yandex.ru>
 */
class ErrorHandler
{
    const TYPE_DEPRECATION = -100;

    private $levels = array(
        E_WARNING           => 'Warning',
        E_NOTICE            => 'Notice',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_STRICT            => 'Runtime Notice',
        E_RECOVERABLE_ERROR => 'Catchable Fatal Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
        E_ERROR             => 'Error',
        E_CORE_ERROR        => 'Core Error',
        E_COMPILE_ERROR     => 'Compile Error',
        E_PARSE             => 'Parse',
    );

    private $level;

    private $reservedMemory;

    private $displayErrors;

    /**
     * @var LoggerInterface[] Loggers for channels
     */
    private static $loggers = array();

    /**
     * Registers the error handler.
     *
     * @param integer $level The level at which the conversion to Exception is done (null to use the error_reporting() value and 0 to disable)
     * @param Boolean $displayErrors Display errors (for dev environment) or just log they (production usage)
     *
     * @return The registered error handler
     */
    public static function register($level = null, $displayErrors = true)
    {
        $handler = new static();
        $handler->setLevel($level);
        $handler->setDisplayErrors($displayErrors);

        ini_set('display_errors', 0);
        set_error_handler(array($handler, 'handle'));
        register_shutdown_function(array($handler, 'handleFatal'));
        $handler->reservedMemory = str_repeat('x', 10240);

        return $handler;
    }

    public function setLevel($level)
    {
        $this->level = null === $level ? error_reporting() : $level;
    }

    public function setDisplayErrors($displayErrors)
    {
        $this->displayErrors = $displayErrors;
    }

    public static function setLogger(LoggerInterface $logger, $channel = 'deprecation')
    {
        self::$loggers[$channel] = $logger;
    }

    /**
     * @throws ContextErrorException When error_reporting returns error
     */
    public function handle($level, $message, $file = 'unknown', $line = 0, $context = array())
    {
        if (0 === $this->level) {
            return false;
        }

        if ($level & (E_USER_DEPRECATED | E_DEPRECATED)) {
            if (isset(self::$loggers['deprecation'])) {
                if (version_compare(PHP_VERSION, '5.4', '<')) {
                    $stack = array_map(
                        function ($row) {
                            unset($row['args']);

                            return $row;
                        },
                        array_slice(debug_backtrace(false), 0, 10)
                    );
                } else {
                    $stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
                }

                self::$loggers['deprecation']->warning($message, array('type' => self::TYPE_DEPRECATION, 'stack' => $stack));
            }

            return true;
        }

        if ($this->displayErrors && error_reporting() & $level && $this->level & $level) {
            throw new ContextErrorException(sprintf('%s: %s in %s line %d', isset($this->levels[$level]) ? $this->levels[$level] : $level, $message, $file, $line), 0, $level, $file, $line, $context);
        }

        return false;
    }

    public function handleFatal()
    {
        if (null === $error = error_get_last()) {
            return;
        }

        unset($this->reservedMemory);
        $type = $error['type'];
        if (0 === $this->level || !in_array($type, array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE))) {
            return;
        }

        if (isset(self::$loggers['emergency'])) {
            $fatal = array(
                'type' => $type,
                'file' => $error['file'],
                'line' => $error['line'],
            );

            self::$loggers['emergency']->emerg($error['message'], $fatal);
        }

        if (!$this->displayErrors) {
            return;
        }

        $this->handleFatalError($error);
    }

    private function handleFatalError($error)
    {
        // get current exception handler
        $exceptionHandler = set_exception_handler(function() {});
        restore_exception_handler();

        if (is_array($exceptionHandler) && $exceptionHandler[0] instanceof ExceptionHandler) {
            $level = isset($this->levels[$error['type']]) ? $this->levels[$error['type']] : $error['type'];
            $message = sprintf('%s: %s in %s line %d', $level, $error['message'], $error['file'], $error['line']);
            $exception = new FatalErrorException($message, 0, $error['type'], $error['file'], $error['line']);

            if ($ex = $this->handleUndefinedFunctionError($error, $exception)) {
                return $exceptionHandler[0]->handle($ex);
            }

            if ($ex = $this->handleClassNotFoundError($error, $exception)) {
                return $exceptionHandler[0]->handle($ex);
            }

            $exceptionHandler[0]->handle($exception);
        }
    }

    private function handleUndefinedFunctionError($error, $exception)
    {
        $messageLen = strlen($error['message']);
        $notFoundSuffix = '()';
        $notFoundSuffixLen = strlen($notFoundSuffix);
        if ($notFoundSuffixLen > $messageLen) {
            return;
        }

        if (0 !== substr_compare($error['message'], $notFoundSuffix, -$notFoundSuffixLen)) {
            return;
        }

        $prefix = 'Call to undefined function ';
        $prefixLen = strlen($prefix);
        if (0 !== strpos($error['message'], $prefix)) {
            return;
        }

        $fullyQualifiedFunctionName = substr($error['message'], $prefixLen, -$notFoundSuffixLen);
        if (false !== $namespaceSeparatorIndex = strrpos($fullyQualifiedFunctionName, '\\')) {
            $functionName = substr($fullyQualifiedFunctionName, $namespaceSeparatorIndex + 1);
            $namespacePrefix = substr($fullyQualifiedFunctionName, 0, $namespaceSeparatorIndex);
            $message = sprintf(
                'Attempted to call function "%s" from namespace "%s" in %s line %d.',
                $functionName,
                $namespacePrefix,
                $error['file'],
                $error['line']
            );
        } else {
            $functionName = $fullyQualifiedFunctionName;
            $message = sprintf(
                'Attempted to call function "%s" from the global namespace in %s line %d.',
                $functionName,
                $error['file'],
                $error['line']
            );
        }

        $candidates = array();
        foreach (get_defined_functions() as $type => $definedFunctionNames) {
            foreach ($definedFunctionNames as $definedFunctionName) {
                if (false !== $namespaceSeparatorIndex = strrpos($definedFunctionName, '\\')) {
                    $definedFunctionNameBasename = substr($definedFunctionName, $namespaceSeparatorIndex + 1);
                } else {
                    $definedFunctionNameBasename = $definedFunctionName;
                }

                if ($definedFunctionNameBasename === $functionName) {
                    $candidates[] = '\\'.$definedFunctionName;
                }
            }
        }

        if ($candidates) {
            $message .= ' Did you mean to call: '.implode(', ', array_map(function ($val) {
                return '"'.$val.'"';
            }, $candidates)).'?';
        }

        return new UndefinedFunctionException($message, $exception);
    }

    private function handleClassNotFoundError($error, $exception)
    {
        $messageLen = strlen($error['message']);
        $notFoundSuffix = '" not found';
        $notFoundSuffixLen = strlen($notFoundSuffix);
        if ($notFoundSuffixLen > $messageLen) {
            return;
        }

        if (0 !== substr_compare($error['message'], $notFoundSuffix, -$notFoundSuffixLen)) {
            return;
        }

        foreach (array('class', 'interface', 'trait') as $typeName) {
            $prefix = ucfirst($typeName).' "';
            $prefixLen = strlen($prefix);
            if (0 !== strpos($error['message'], $prefix)) {
                continue;
            }

            $fullyQualifiedClassName = substr($error['message'], $prefixLen, -$notFoundSuffixLen);
            if (false !== $namespaceSeparatorIndex = strrpos($fullyQualifiedClassName, '\\')) {
                $className = substr($fullyQualifiedClassName, $namespaceSeparatorIndex + 1);
                $namespacePrefix = substr($fullyQualifiedClassName, 0, $namespaceSeparatorIndex);
                $message = sprintf(
                    'Attempted to load %s "%s" from namespace "%s" in %s line %d. Do you need to "use" it from another namespace?',
                    $typeName,
                    $className,
                    $namespacePrefix,
                    $error['file'],
                    $error['line']
                );
            } else {
                $className = $fullyQualifiedClassName;
                $message = sprintf(
                    'Attempted to load %s "%s" from the global namespace in %s line %d. Did you forget a use statement for this %s?',
                    $typeName,
                    $className,
                    $error['file'],
                    $error['line'],
                    $typeName
                );
            }

            if ($classes = $this->getClassCandidates($className)) {
                $message .= sprintf(' Perhaps you need to add a use statement for one of the following class: %s.', implode(', ', $classes));
            }

            return new ClassNotFoundException($message, $exception);
        }
    }

    /**
     * Tries to guess the full namespace for a given class name.
     *
     * By default, it looks for PSR-0 classes registered via a Symfony or a Composer
     * autoloader (that should cover all common cases).
     *
     * @param string $class A class name (without its namespace)
     *
     * @return array An array of possible fully qualified class names
     */
    private function getClassCandidates($class)
    {
        if (!is_array($functions = spl_autoload_functions())) {
            return array();
        }

        // find Symfony and Composer autoloaders
        $classes = array();
        foreach ($functions as $function) {
            if (!is_array($function)) {
                continue;
            }

            // get class loaders wrapped by DebugClassLoader
            if ($function[0] instanceof DebugClassLoader && method_exists($function[0], 'getClassLoader')) {
                $function[0] = $function[0]->getClassLoader();
            }

            if ($function[0] instanceof ComposerClassLoader || $function[0] instanceof SymfonyClassLoader) {
                foreach ($function[0]->getPrefixes() as $paths) {
                    foreach ($paths as $path) {
                        $classes = array_merge($classes, $this->findClassInPath($function[0], $path, $class));
                    }
                }
            }
        }

        return $classes;
    }

    private function findClassInPath($loader, $path, $class)
    {
        if (!$path = realpath($path)) {
            continue;
        }

        $classes = array();
        $filename = $class.'.php';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if ($filename == $file->getFileName() && $class = $this->convertFileToClass($loader, $path, $file->getPathName())) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function convertFileToClass($loader, $path, $file)
    {
        // We cannot use the autoloader here as most of them use require; but if the class
        // is not found, the new autoloader call will require the file again leading to a
        // "cannot redeclare class" error.
        require_once $file;

        $file = str_replace(array($path.'/', '.php'), array('', ''), $file);

        // is it a namespaced class?
        $class = str_replace('/', '\\', $file);
        if (class_exists($class, false) || interface_exists($class, false) || (function_exists('trait_exists') && trait_exists($class, false))) {
            return $class;
        }

        // is it a PEAR-like class name instead?
        $class = str_replace('/', '_', $file);
        if (class_exists($class, false) || interface_exists($class, false) || (function_exists('trait_exists') && trait_exists($class, false))) {
            return $class;
        }
    }
}
