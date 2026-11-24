<?php

namespace wise\support\tools;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Email
{

    /**
     * phpmailer对象
     * @var PHPMailer|null
     */
    protected $mail = null;

    /**
     * 是否已尝试初始化 PHPMailer（避免重复检测与重复实例化）
     * @var bool
     */
    protected $mailerInited = false;

    /**
     * 错误内容
     */
    protected $_error = '';

    /**
     * 默认配置
     * 说明：所有被读取的配置项均需在此声明，避免 PHP 8 下 Undefined array key 警告
     */
    public $options = [
        'charset'       => 'utf-8',
        'debug'         => 0,
        'send_type'     => '',
        'verify_type'   => 0,
        'smtp_host'     => '',
        'smtp_port'     => 25,
        'smtp_user'     => '',
        'smtp_password' => '',
        'from'          => '',
        'from_name'     => '',
        'to'            => '',
        'to_name'       => '',
        'subject'       => '',
        'body'          => '',
        'ishtml'        => true,
    ];

    /**
     * 初始化
     * @access public
     * @param array $options 参数
     * @return static
     */
    public static function instance($options = [])
    {
        return new static($options);
    }

    /**
     * 构造函数
     * 说明：phpmailer/phpmailer 为可选（suggest）依赖，此处不再直接实例化，
     *      改为首次使用 SMTP 时惰性初始化，避免依赖缺失导致致命错误
     * @param array $options
     */
    public function __construct($options = [])
    {
        $this->options = array_merge($this->options, $options);

        $this->from($this->options['from'], $this->options['from_name']);
    }

    /**
     * 惰性初始化 PHPMailer
     * @return bool 是否可用
     */
    protected function initMailer()
    {
        if ($this->mailerInited) {
            if (null === $this->mail) {
                $this->setError('未安装 phpmailer/phpmailer 依赖，无法使用 SMTP 发送，请执行 composer require phpmailer/phpmailer');
                return false;
            }
            return true;
        }
        $this->mailerInited = true;

        if (!class_exists(PHPMailer::class)) {
            $this->setError('未安装 phpmailer/phpmailer 依赖，无法使用 SMTP 发送，请执行 composer require phpmailer/phpmailer');
            return false;
        }

        $securArr   = [
            1 => PHPMailer::ENCRYPTION_STARTTLS,
            2 => PHPMailer::ENCRYPTION_SMTPS,
        ];
        $verifyType = (int) $this->options['verify_type'];

        $mail              = new PHPMailer(true);
        $mail->CharSet     = $this->options['charset'];
        $mail->SMTPDebug   = (int) $this->options['debug'];
        $mail->Debugoutput = 'error_log';
        $mail->isSMTP();
        $mail->Host       = $this->options['smtp_host'];
        $mail->Port       = (int) $this->options['smtp_port'];
        $mail->SMTPSecure = isset($securArr[$verifyType]) ? $securArr[$verifyType] : '';
        $mail->SMTPAuth   = '' !== (string) $this->options['smtp_user'];
        $mail->Username   = $this->options['smtp_user'];
        $mail->Password   = $this->options['smtp_password'];

        $this->mail = $mail;

        return true;
    }

    /**
     * 设置邮件主题
     * @param string $subject
     * @return $this
     */
    public function subject($subject)
    {
        $this->options['subject'] = $subject;
        return $this;
    }

    /**
     * 设置发件人
     * @param string $email
     * @param string $name
     * @return $this
     */
    public function from($email, $name = '')
    {
        $this->options['from']      = $email;
        $this->options['from_name'] = $name;
        return $this;
    }

    /**
     * 设置收件人
     * @param string $email
     * @param string $name
     * @return $this
     */
    public function to($email, $name = '')
    {
        $this->options['to']      = $email;
        $this->options['to_name'] = $name;
        return $this;
    }

    /**
     * 设置邮件正文
     * @param string $body
     * @param boolean $ishtml
     * @return $this
     */
    public function message($body, $ishtml = true)
    {
        $this->options['body']   = $body;
        $this->options['ishtml'] = $ishtml;
        return $this;
    }

    /**
     * 获取最后产生的错误
     * @return string
     */
    public function getError()
    {
        return $this->_error;
    }

    /**
     * 设置错误
     * @param string $error 信息信息
     */
    protected function setError($error)
    {
        $this->_error = $error;
    }

    /**
     * 发送邮件
     * @return boolean
     */
    public function send()
    {
        $result       = false;
        $this->_error = '';

        switch ((string) $this->options['send_type']) {
            case 'smtp':
                if (!$this->initMailer()) {
                    return false;
                }

                $from = trim((string) $this->options['from']);
                $to   = trim((string) $this->options['to']);
                if ('' === $from || '' === $to) {
                    $this->setError('发件人或收件人邮箱未配置');
                    return false;
                }

                try {
                    $this->mail->clearAllRecipients();
                    $this->mail->clearAttachments();
                    $this->mail->setFrom($from, $this->options['from_name']);
                    $this->mail->addAddress($to, $this->options['to_name']);
                    $this->mail->Subject = $this->options['subject'];
                    if (!empty($this->options['ishtml'])) {
                        $this->mail->msgHTML($this->options['body']);
                    } else {
                        $this->mail->isHTML(false);
                        $this->mail->Body = $this->options['body'];
                    }
                    if (!$this->mail->send()) {
                        $this->setError($this->mail->ErrorInfo);
                        return false;
                    }
                    return true;
                } catch (PHPMailerException $e) {
                    $this->setError($e->getMessage());
                    return false;
                }
            case 'mail':
                $to       = trim($this->cleanHeader($this->options['to']));
                $from     = trim($this->cleanHeader($this->options['from']));
                $toName   = trim(str_replace(['<', '>', '"'], '', $this->cleanHeader($this->options['to_name'])));
                $fromName = trim(str_replace(['<', '>', '"'], '', $this->cleanHeader($this->options['from_name'])));
                $subject  = trim($this->cleanHeader($this->options['subject']));

                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    $this->setError('收件人邮箱格式不合法');
                    return false;
                }

                $charset = $this->options['charset'];
                $headers = 'MIME-Version: 1.0' . "\r\n";
                $headers .= "Content-type: text/html; charset={$charset}" . "\r\n";
                $headers .= "To: {$toName} <{$to}>\r\n";
                $headers .= "From: {$fromName} <{$from}>\r\n";

                $result    = mail($to, $this->encodeSubject($subject, $charset), $this->options['body'], $headers);
                $lastError = error_get_last();
                $this->setError($result ? '' : (isset($lastError['message']) ? $lastError['message'] : 'mail() 发送失败'));
                break;
            default:
                $this->setError('邮件功能已关闭');
                break;
        }
        return $result;
    }

    /**
     * 清除邮件头中的 CR/LF 等换行字符，防止邮件头注入
     * @param string $value
     * @return string
     */
    protected function cleanHeader($value)
    {
        return str_replace(["\r", "\n", "\0"], '', (string) $value);
    }

    /**
     * 编码邮件主题，保证中文等非 ASCII 主题不乱码
     * @param string $subject
     * @param string $charset
     * @return string
     */
    protected function encodeSubject($subject, $charset)
    {
        if ('' === $subject) {
            return '';
        }
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($subject, $charset, 'B', "\r\n");
        }
        return '=?' . strtoupper($charset) . '?B?' . base64_encode($subject) . '?=';
    }

}
