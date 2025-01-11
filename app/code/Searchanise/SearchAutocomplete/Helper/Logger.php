<?php

namespace Searchanise\SearchAutocomplete\Helper;

use Searchanise\SearchAutocomplete\Helper\Data as DataHelper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Escaper;
use Psr\Log\LoggerInterface;

/**
 * Searchanise logger
 */
class Logger extends AbstractHelper
{
    const TYPE_ERROR   = 'Error';
    const TYPE_INFO    = 'Info';
    const TYPE_WARNING = 'Warning';
    const TYPE_DEBUG   = 'Debug';

    private static $allowedTypes = [
        self::TYPE_ERROR,
        self::TYPE_INFO,
        self::TYPE_WARNING,
        self::TYPE_DEBUG,
    ];

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DataHelper
     */
    private $dataHelper;

    /**
     * @ Escaper
     */
    private $escaper;

    /**
     * @var HttpResponse
     */
    private $response = null;

    public function __construct(
        Context $context,
        LoggerInterface $logger,
        DataHelper $dataHelper,
        Escaper $escaper
    ) {
        $this->dataHelper = $dataHelper;
        $this->escaper = $escaper;
        $this->logger = $logger;

        parent::__construct($context);
    }

    /**
     * Log message
     */
    public function log()
    {
        $args = func_get_args();
        $message = [];
        $type = array_pop($args);

        // Check log type
        if (!in_array($type, self::$allowedTypes)) {
            if ($type !== null) {
                array_push($args, $type);
            }

            $type = self::TYPE_ERROR;
        }

        $process_data = function ($v) use (&$process_data) {
            if (!is_array($v)) {
                if (mb_strlen($v) > 512 || preg_match('~[^\x20-\x7E\t\r\n]~', $v)) {
                    $v = '=== BINARY DATA ===';
                }
            } else {
                foreach ($v as $_k => $_v) {
                    $v[$_k] = $process_data($_v);
                }
            }

            return $v;
        };

        // Check log message
        if (!empty($args)) {
            foreach ($args as $k => $v) {
                $v = $process_data($v);

                if ($v instanceof \Magento\Framework\Phrase) {
                    $message[] = $v->render();
                } else {
                    // phpcs:ignore Magento2.Functions.DiscouragedFunction
                    $message[] = print_r($v, true);
                }
            }
        }
        $message = implode("\n", $message);

        switch ($type) {
            case self::TYPE_ERROR:
                $this->logger->error('Searchanise #' . $message);
                break;
            case self::TYPE_WARNING:
                $this->logger->warning('Searchanise #' . $message);
                break;
            case self::TYPE_DEBUG:
                $this->logger->debug('Searchanise #' . $message);
                break;
            default:
                $this->logger->info('Searchanise #' . $message);
        }

        if ($this->dataHelper->checkDebug(true)) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            call_user_func_array([$this, 'printR'], $args);
        }

        return true;
    }

    public function setResponseContext(HttpResponse $httpResponse = null)
    {
        $this->response = $httpResponse;
        return $this;
    }

    public function printR()
    {
        static $count = 0;

        $args = func_get_args();
        $content = '';
        $time = date('c');

        if (!empty($args)) {
            // phpcs:disable Generic.Files.LineLength.TooLong
            $content .= '<ol style="font-family: Courier; font-size: 12px; border: 1px solid #dedede; background-color: #efefef; float: left; padding-right: 20px;">';
            $content .= '<li><pre>===== ' . $time . '===== </pre></li>' . "\n";

            foreach ($args as $k => $v) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $v = $this->escaper->escapeHtml(print_r($v, true));
                if ($v == '') {
                    $v = '    ';
                }

                $content .= '<li><pre>' . $v . "\n" . '</pre></li>';
            }

            $content .= '</ol><div style="clear:left;"></div>';
        }

        $count++;

        if (!empty($content) && !empty($this->response)) {
            $this->response->appendBody($content);
        }
    }
}
