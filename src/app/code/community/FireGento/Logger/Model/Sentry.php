<?php
/**
 * This file is part of a FireGento e.V. module.
 *
 * This FireGento e.V. module is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 3 as
 * published by the Free Software Foundation.
 *
 * This script is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * PHP version 8
 *
 * @category  FireGento
 * @package   FireGento_Logger
 * @author    FireGento Team <team@firegento.com>
 * @copyright 2013 FireGento Team (http://www.firegento.com)
 * @license   http://opensource.org/licenses/gpl-3.0 GNU General Public License, version 3 (GPLv3)
 */

/**
 * Model for Sentry logging using the modern sentry/sentry v4 SDK.
 *
 * Replaces the legacy Raven_Client implementation which is incompatible with PHP 8.x.
 *
 * DSN resolution order:
 *   1. SENTRY_DSN environment variable  (preferred — set per environment)
 *   2. Admin config: System > Config > Advanced > Logger > Sentry > DSN
 *
 * SENTRY_ENVIRONMENT environment variable is read automatically by the SDK.
 * If no DSN is configured the writer silently does nothing.
 *
 * @category FireGento
 * @package  FireGento_Logger
 * @author   FireGento Team <team@firegento.com>
 *
 * see: https://github.com/magento-hackathon/LoggerSentry
 */
class FireGento_Logger_Model_Sentry extends FireGento_Logger_Model_Abstract
{
    /** @var bool|null null = uninitialised, false = no DSN, true = ready */
    protected static $_ready = null;

    protected $_priorityToSeverity = [
        0 /*Zend_Log::EMERG*/  => 'fatal',
        1 /*Zend_Log::ALERT*/  => 'fatal',
        2 /*Zend_Log::CRIT*/   => 'fatal',
        3 /*Zend_Log::ERR*/    => 'error',
        4 /*Zend_Log::WARN*/   => 'warning',
        5 /*Zend_Log::NOTICE*/ => 'info',
        6 /*Zend_Log::INFO*/   => 'info',
        7 /*Zend_Log::DEBUG*/  => 'debug',
    ];

    /** @var string|null */
    protected $_fileName;

    public function __construct($fileName = null)
    {
        $this->_fileName = $fileName ? basename($fileName) : null;
    }

    /**
     * Convert a severity name string to a Sentry Severity object.
     */
    protected function _severityFromName(string $name): \Sentry\Severity
    {
        return match ($name) {
            'fatal'   => \Sentry\Severity::fatal(),
            'warning' => \Sentry\Severity::warning(),
            'info'    => \Sentry\Severity::info(),
            'debug'   => \Sentry\Severity::debug(),
            default   => \Sentry\Severity::error(),
        };
    }

    /**
     * Public entry point called by Observer::initLoggerClient() early in the request.
     *
     * @return bool true if SDK is ready to receive events
     */
    public function init(): bool
    {
        return $this->_initSentry();
    }

    /**
     * Initialise the Sentry SDK once per process.
     *
     * Expects the sentry/sentry v4 package to be available via Composer.
     * The vendor/autoload.php is expected one directory above the Magento root (BP).
     *
     * @return bool true if SDK is ready to receive events
     */
    protected function _initSentry(): bool
    {
        if (self::$_ready !== null) {
            return (bool) self::$_ready;
        }

        $dsn = getenv('SENTRY_DSN') ?: Mage::helper('firegento_logger')->getLoggerConfig('sentry/public_dsn');

        if (!$dsn) {
            return self::$_ready = false;
        }

        $autoloader = BP . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!file_exists($autoloader)) {
            Mage::log('FireGento_Logger_Model_Sentry: Composer autoloader not found at ' . $autoloader, Zend_Log::ERR, 'exception.log');
            return self::$_ready = false;
        }
        require_once $autoloader;

        // Restrict Sentry's PHP error handler to fatal errors only.
        //
        // Sentry\init() registers a PHP error handler that converts PHP errors into
        // ErrorExceptions. If E_WARNING or similar are included, Magento silently catches
        // those ErrorExceptions inside toHtml() (non-developer mode) and returns empty blocks.
        // The Magento logger integration already routes exceptions to Sentry via _write(),
        // so we only need the SDK's own handler to cover fatal errors that bypass that path.
        //
        // Note: E_STRICT (2048) was removed in PHP 8 — referencing the constant itself
        // triggers E_DEPRECATED, so it is intentionally omitted here.
        $options = [
            'dsn'         => $dsn,
            'error_types' => E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR,
        ];

        \Sentry\init($options);

        return self::$_ready = true;
    }

    /**
     * Write a log event to Sentry.
     *
     * @param FireGento_Logger_Model_Event $event
     * @throws Zend_Log_Exception
     */
    protected function _write($event)
    {
        try {
            Mage::helper('firegento_logger')->addEventMetadata($event, null, $this->_enableBacktrace);

            if (!$this->_initSentry()) {
                return;
            }

            if ($this->_shouldSkip($event)) {
                return;
            }

            if (!isset($event['priority']) || $event['priority'] === Zend_Log::ERR) {
                $this->_assumePriorityByMessage($event);
            }
            $priority     = isset($event['priority']) ? (int) $event['priority'] : 3;
            $severityName = $this->_priorityToSeverity[$priority] ?? 'error';
            $severity     = $this->_severityFromName($severityName);

            $tags = [
                'target'    => (string) $this->_fileName,
                'storeCode' => (string) ($event->getStoreCode() ?: 'unknown'),
                'requestId' => (string) $event->getRequestId(),
            ];
            $extra = ['timeElapsed' => $event->getTimeElapsed()];

            if ($event->getAdminUserId()) {
                $extra['adminUserId'] = $event->getAdminUserId();
            }
            if ($event->getAdminUserName()) {
                $extra['adminUserName'] = $event->getAdminUserName();
            }

            if (class_exists('Mage')) {
                if (Mage::registry('logger_data_tags')) {
                    $tags = array_merge($tags, Mage::registry('logger_data_tags'));
                }
                if (Mage::registry('logger_data_extra')) {
                    $extra = array_merge($extra, Mage::registry('logger_data_extra'));
                }
            }

            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($event, $severity, $tags, $extra): void {
                foreach ($tags as $key => $value) {
                    $scope->setTag((string) $key, (string) $value);
                }
                foreach ($extra as $key => $value) {
                    $scope->setExtra((string) $key, $value);
                }

                if ($event->getException()) {
                    \Sentry\captureException($event->getException());
                } else {
                    \Sentry\captureMessage((string) $event['message'], $severity);
                }
            });

        } catch (Exception $e) {
            throw new Zend_Log_Exception($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Return true if this event should be silently dropped and not sent to Sentry.
     *
     * @param FireGento_Logger_Model_Event $event
     * @return bool
     */
    protected function _shouldSkip($event): bool
    {
        $exception = $event->getException();
        if (!$exception) {
            return false;
        }
        // Base Exception with an empty message is used as control-flow in core
        // controllers (e.g. confirmationAction "customer not found") — never a bug.
        if (get_class($exception) === 'Exception' && $exception->getMessage() === '') {
            return true;
        }
        return false;
    }

    /**
     * Try to infer a log priority from the message text when not explicitly set.
     *
     * @param FireGento_Logger_Model_Event $event
     * @return $this
     */
    protected function _assumePriorityByMessage(&$event)
    {
        $msg = (string) $event['message'];
        if (stripos($msg, 'warn') === 0 || stripos($msg, 'user warn') === 0) {
            $event['priority'] = 4;
        } elseif (
            stripos($msg, 'notice') === 0 ||
            stripos($msg, 'user notice') === 0 ||
            stripos($msg, 'strict notice') === 0 ||
            stripos($msg, 'deprecated') === 0
        ) {
            $event['priority'] = 5;
        }
        return $this;
    }
}
