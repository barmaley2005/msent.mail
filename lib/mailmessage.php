<?
namespace MSEnt\Mail;

use Bitrix\Main\Type;
use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

class MailMessageTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_ms_mail_message';
    }

    public static function getMap()
    {
        return array(
            new Entity\IntegerField('ID', array("primary" => true, "autocomplete" => true, "title" => "ID")),
            new Entity\IntegerField('MAILBOX_ID', array("title" => Loc::getMessage("MSENT_MAILMESSAGE_MAILBOX_ID"))),
            new Entity\ReferenceField(
                'MAILBOX',
                'MSEnt\Mail\MailBox',
                array('=this.MAILBOX_ID' => 'ref.ID')
            ),
            new Entity\StringField('MESSAGE_UID',array("size"=>16,"required"=>true,"title" => Loc::getMessage("MSENT_MAILMESSAGE_MESSAGE_UID"))),
            new Entity\StringField('STATUS_ID',array("size"=>16,"default_value"=>"N", "required"=>true,"title" => Loc::getMessage("MSENT_MAILMESSAGE_STATUS_ID"))),
            new Entity\ReferenceField(
                'STATUS',
                'MSEnt\Mail\MailStatus',
                array('=this.STATUS_ID' => 'ref.ID')
            ),
            new Entity\TextField('HEADER',array("title" => Loc::getMessage("MSENT_MAILMESSAGE_HEADER"))),
            new Entity\DatetimeField('FIELD_DATE', array("title" => Loc::getMessage("MSENT_MAILMESSAGE_FIELD_DATE"))),
            new Entity\StringField('FIELD_FROM',array("size"=>255,"title" => Loc::getMessage("MSENT_MAILMESSAGE_FIELD_FROM"))),
            new Entity\StringField('FIELD_REPLY_TO',array("size"=>255,"title" => Loc::getMessage("MSENT_MAILMESSAGE_FIELD_REPLY_TO"))),
            new Entity\StringField('FIELD_TO',array("size"=>255,"title" => Loc::getMessage("MSENT_MAILMESSAGE_FIELD_TO"))),
            new Entity\StringField('FIELD_CC',array("size"=>255,"title" => Loc::getMessage("MSENT_MAILMESSAGE_FIELD_CC"))),
            new Entity\StringField('FIELD_BCC',array("size"=>255,"title" => Loc::getMessage("MSENT_MAILMESSAGE_FIELD_BCC"))),
            new Entity\StringField('MSG_ID',array("size"=>255,"title" => Loc::getMessage("MSENT_MAILMESSAGE_MSG_ID"))),
            new Entity\StringField('IN_REPLY_TO',array("size"=>255,"title" => Loc::getMessage("MSENT_MAILMESSAGE_IN_REPLY_TO"))),
            new Entity\IntegerField('FIELD_PRIORITY',array("default_value"=>3,"title" => Loc::getMessage("MSENT_MAILMESSAGE_FIELD_PRIORITY"))),
            new Entity\StringField('SUBJECT',array("size"=>255,"title" => Loc::getMessage("MSENT_MAILMESSAGE_SUBJECT"))),
            new Entity\TextField('BODY',array("title" => Loc::getMessage("MSENT_MAILMESSAGE_BODY"))),
            new Entity\DatetimeField('DATE_INSERT', array('default_value' => new Type\DateTime,"title" => Loc::getMessage("MSENT_MAILMESSAGE_DATE_INESRT"))),
        );
    }

    private static function decodeMessageBody($header, $body, $charset)
    {
        $encoding = strtolower($header->getHeader('CONTENT-TRANSFER-ENCODING'));

        if ($encoding == 'base64')
            $body = base64_decode($body);
        elseif ($encoding == 'quoted-printable')
            $body = quoted_printable_decode($body);
        elseif ($encoding == 'x-uue')
            $body = \MSEnt\Mail\MailHeader::uue_decode($body);

        $content_type = strtolower($header->content_type);
        if ((strpos($content_type, 'plain') !== false || strpos($content_type, 'html') !== false || strpos($content_type, 'text') !== false) && strpos($content_type, 'x-vcard') === false)
            $body = \MSEnt\Mail\MailHeader::convertCharset($body, $header->charset, $charset);

        return array(
            'CONTENT-TYPE' => $content_type,
            'CONTENT-ID'   => $header->content_id,
            'BODY'         => $body,
            'FILENAME'     => $header->filename
        );
    }

    public static function parseMessage($message, $charset)
    {
        $headerP = strpos($message, "\r\n\r\n");

        $rawHeader = substr($message, 0, $headerP);
        $body      = substr($message, $headerP+4);

        $header = \MSEnt\Mail\MailHeader::parseHeader($rawHeader, $charset);

        $htmlBody = '';
        $textBody = '';

        $parts = array();

        if ($header->IsMultipart())
        {
            $startB = "\r\n--" . $header->getBoundary() . "\r\n";
            $endB   = "\r\n--" . $header->getBoundary() . "--\r\n";

            $startP = strpos($message, $startB)+strlen($startB);
            $endP   = strpos($message, $endB);

            $data = substr($message, $startP, $endP-$startP);

            $isHtml = false;
            $rawParts = preg_split("/\r\n--".preg_quote($header->getBoundary(), '/')."\r\n/s", $data);
            $tmpParts = array();
            foreach ($rawParts as $part)
            {
                if (substr($part, 0, 2) == "\r\n")
                    $part = "\r\n" . $part;

                list(, $subHtml, $subText, $subParts) = self::parseMessage($part, $charset);

                if ($subHtml)
                    $isHtml = true;

                if ($subText)
                    $tmpParts[] = array($subHtml, $subText);

                $parts = array_merge($parts, $subParts);
            }

            if (strtolower($header->multipartType()) == 'alternative')
            {
                foreach ($tmpParts as $part)
                {
                    if ($part[0])
                    {
                        if (!$textBody || $htmlBody && (strlen($htmlBody) < strlen($part[0])))
                        {
                            $htmlBody = $part[0];
                            $textBody = $part[1];
                        }
                    }
                    else
                    {
                        if (!$textBody || strlen($textBody) < strlen($part[1]))
                        {
                            $htmlBody = '';
                            $textBody = $part[1];
                        }
                    }
                }
            }
            else
            {
                foreach ($tmpParts as $part)
                {
                    if ($textBody)
                        $textBody .= "\r\n\r\n";
                    $textBody .= $part[1];

                    if ($isHtml)
                    {
                        if ($htmlBody)
                            $htmlBody .= "\r\n\r\n";

                        $htmlBody .= $part[0] ?: $part[1];
                    }
                }
            }
        }
        else
        {
            $bodyPart = self::decodeMessageBody($header, $body, $charset);

            if (!$bodyPart['FILENAME'] && strpos(strtolower($bodyPart['CONTENT-TYPE']), 'text/') === 0)
            {
                if (strtolower($bodyPart['CONTENT-TYPE']) == 'text/html')
                {
                    $htmlBody = $bodyPart['BODY'];
                    $textBody = htmlToTxt($bodyPart['BODY']);
                }
                else
                {
                    $textBody = $bodyPart['BODY'];
                }
            }
            else
            {
                $parts[] = $bodyPart;
            }
        }

        return array($header, $htmlBody, $textBody, $parts);
    }

}

?>