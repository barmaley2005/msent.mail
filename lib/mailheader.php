<?
namespace MSEnt\Mail;

use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

class MailHeader
{
    var $arHeader = Array();
    var $arHeaderLines = Array();
    var $strHeader = "";
    var $bMultipart = false;
    var $content_type, $boundary, $charset, $filename, $MultipartType="mixed";
    public $content_id = '';

    function convertHeader($encoding, $type, $str, $charset)
    {
        if(strtoupper($type)=="B")
            $str = base64_decode($str);
        else
            $str = quoted_printable_decode(str_replace("_", " ", $str));

        $str = self::convertCharset($str, $encoding, $charset);

        return $str;
    }

    function decodeHeader($str, $charset_to, $charset_document)
    {
        while(preg_match('/(=\?[^?]+\?(Q|B)\?[^?]*\?=)(\s)+=\?/i', $str))
            $str = preg_replace('/(=\?[^?]+\?(Q|B)\?[^?]*\?=)(\s)+=\?/i', '\1=?', $str);
        if(!preg_match("'=\?(.*)\?(B|Q)\?(.*)\?='i", $str))
        {
            if(strlen($charset_document)>0 && $charset_document!=$charset_to)
                $str = self::convertCharset($str, $charset_document, $charset_to);
        }
        else
        {
            $str = preg_replace_callback(
                "'=\?(.*?)\?(B|Q)\?(.*?)\?='i",
                create_function('$m', "return \\MSEnt\\Mail\\MailHeader::convertHeader(\$m[1], \$m[2], \$m[3], '".AddSlashes($charset_to)."');"),
                $str
            );
        }

        return $str;
    }

    function parse($message_header, $charset)
    {
        if(preg_match("'content-type:.*?charset=([^\r\n;]+)'is", $message_header, $res))
            $this->charset = strtolower(trim($res[1], ' "'));
        /*elseif($this->charset=='' && defined("BX_MAIL_DEFAULT_CHARSET"))
            $this->charset = BX_MAIL_DEFAULT_CHARSET; TO-DO Fix Me*/

        $ar_message_header_tmp = explode("\r\n", $message_header);

        $n = -1;
        $bConvertSubject = false;
        for($i = 0, $num = count($ar_message_header_tmp); $i < $num; $i++)
        {
            $line = $ar_message_header_tmp[$i];
            if(($line[0]==" " || $line[0]=="\t") && $n>=0)
            {
                $line = ltrim($line, " \t");
                $bAdd = true;
            }
            else
                $bAdd = false;

            $line = MailHeader::DecodeHeader($line, $charset, $this->charset);

            if($bAdd)
                $this->arHeaderLines[$n] = $this->arHeaderLines[$n].$line;
            else
            {
                $n++;
                $this->arHeaderLines[] = $line;
            }
        }

        $this->arHeader = Array();
        for($i = 0, $num = count($this->arHeaderLines); $i < $num; $i++)
        {
            $p = strpos($this->arHeaderLines[$i], ":");
            if($p>0)
            {
                $header_name = strtoupper(trim(substr($this->arHeaderLines[$i], 0, $p)));
                $header_value = trim(substr($this->arHeaderLines[$i], $p+1));
                $this->arHeader[$header_name] = $header_value;
            }
        }

        $full_content_type = $this->arHeader["CONTENT-TYPE"];
        if(strlen($full_content_type)<=0)
            $full_content_type = "text/plain";

        if(!($p = strpos($full_content_type, ";")))
            $p = strlen($full_content_type);

        $this->content_type = trim(substr($full_content_type, 0, $p));
        if(strpos(strtolower($this->content_type), "multipart/") === 0)
        {
            $this->bMultipart = true;
            if (!preg_match("'boundary\s*=(.+);'i", $full_content_type, $res))
                preg_match("'boundary\s*=(.+)'i", $full_content_type, $res);

            $this->boundary = trim($res[1], '"');
            if($p = strpos($this->content_type, "/"))
                $this->MultipartType = substr($this->content_type, $p+1);
        }

        if($p < strlen($full_content_type))
        {
            $add = substr($full_content_type, $p+1);
            if(preg_match("'name=(.+)'i", $full_content_type, $res))
                $this->filename = trim($res[1], '"');
        }

        $cd = $this->arHeader["CONTENT-DISPOSITION"];
        if(strlen($cd)>0 && preg_match("'filename=([^;]+)'i", $cd, $res))
            $this->filename = trim($res[1], '"');

        if($this->arHeader["CONTENT-ID"]!='')
            $this->content_id = trim($this->arHeader["CONTENT-ID"], '"<>');

        $this->strHeader = implode("\r\n", $this->arHeaderLines);

        return true;
    }

    function isMultipart()
    {
        return $this->bMultipart;
    }

    function multipartType()
    {
        return strtolower($this->MultipartType);
    }

    function getBoundary()
    {
        return $this->boundary;
    }

    function getHeader($type)
    {
        return $this->arHeader[strtoupper($type)];
    }

    public static function convertCharset($str, $from, $to)
    {
        $from = trim(strtolower($from));
        $to = trim(strtolower($to));

        if(($from=='utf-8' || $to == 'utf-8') || defined('BX_UTF'))
            return $GLOBALS['APPLICATION']->ConvertCharset($str, $from, $to);


        if($from=='windows-1251' || $from=='cp1251')
            $from = 'w';
        elseif(strpos($from, 'koi8')===0)
            $from = 'k';
        elseif($from=='dos-866')
            $from = 'd';
        elseif($from=='iso-8859-5')
            $from = 'i';
        else
            $from = '';

        if($to=='windows-1251' || $to=='cp1251')
            $to = 'w';
        elseif(strpos($to, 'koi8')===0)
            $to = 'k';
        elseif($to=='dos-866')
            $to = 'd';
        elseif($to=='iso-8859-5')
            $to = 'i';
        else
            $to = '';

        if(strlen($from)>0 && strlen($to)>0)
        {
            $str = convert_cyr_string($str, $from, $to);
        }
        return $str;

    }

    public static function uue_decode($str)
    {
        preg_match("/begin [0-7]{3} .+?\r?\n(.+)?\r?\nend/i", $str, $reg);

        $str = $reg[1];
        $res = '';
        $str = preg_split("/\r?\n/", trim($str));
        $strlen = count($str);

        for ($i = 0; $i < $strlen; $i++)
        {
            $pos = 1;
            $d = 0;
            $len= (int)(((ord(substr($str[$i],0,1)) -32) - ' ') & 077);

            while (($d + 3 <= $len) AND ($pos + 4 <= strlen($str[$i])))
            {
                $c0 = (ord(substr($str[$i],$pos,1)) ^ 0x20);
                $c1 = (ord(substr($str[$i],$pos+1,1)) ^ 0x20);
                $c2 = (ord(substr($str[$i],$pos+2,1)) ^ 0x20);
                $c3 = (ord(substr($str[$i],$pos+3,1)) ^ 0x20);
                $res .= chr(((($c0 - ' ') & 077) << 2) | ((($c1 - ' ') & 077) >> 4)).
                    chr(((($c1 - ' ') & 077) << 4) | ((($c2 - ' ') & 077) >> 2)).
                    chr(((($c2 - ' ') & 077) << 6) |  (($c3 - ' ') & 077));

                $pos += 4;
                $d += 3;
            }

            if (($d + 2 <= $len) && ($pos + 3 <= strlen($str[$i])))
            {
                $c0 = (ord(substr($str[$i],$pos,1)) ^ 0x20);
                $c1 = (ord(substr($str[$i],$pos+1,1)) ^ 0x20);
                $c2 = (ord(substr($str[$i],$pos+2,1)) ^ 0x20);
                $res .= chr(((($c0 - ' ') & 077) << 2) | ((($c1 - ' ') & 077) >> 4)).
                    chr(((($c1 - ' ') & 077) << 4) | ((($c2 - ' ') & 077) >> 2));

                $pos += 3;
                $d += 2;
            }

            if (($d + 1 <= $len) && ($pos + 2 <= strlen($str[$i])))
            {
                $c0 = (ord(substr($str[$i],$pos,1)) ^ 0x20);
                $c1 = (ord(substr($str[$i],$pos+1,1)) ^ 0x20);
                $res .= chr(((($c0 - ' ') & 077) << 2) | ((($c1 - ' ') & 077) >> 4));
            }
        }

        return $res;
    }

    function extractAllMailAddresses($emails)
    {
        $result = array();
        $arEMails = explode(",", $emails);
        foreach($arEMails as $mail)
        {
            $result[] = self::ExtractMailAddress($mail);
        }
        return $result;
    }


    function extractMailAddress($email)
    {
        $email = trim($email);
        if(($pos = strpos($email, "<"))!==false)
            $email = substr($email, $pos+1);
        if(($pos = strpos($email, ">"))!==false)
            $email = substr($email, 0, $pos);
        return strtolower($email);
    }

    public static function parseHeader($message_header, $charset)
    {
        $h = new MailHeader();
        $h->Parse($message_header, $charset);
        return $h;
    }
}



?>