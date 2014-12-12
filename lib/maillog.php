<?
namespace MSEnt\Mail;

use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

class MailLog
{
    public static function error($ERROR_ID,$additionalInfo = false)
    {
        echo 'ERROR '.$ERROR_ID."<br>";
        return false;
    }
}

?>