<?
namespace MSEnt\Mail;

use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

class MailStatusTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_ms_mail_status';
    }

    public static function getMap()
    {
        return array(
            new Entity\StringField('ID',array("size"=>16,"primary" => true, "required"=>true)),
            new Entity\StringField('NAME',array("size"=>255,"title"=>Loc::getMessage("MSENT_MAILSTATUS_NAME"))),
        );
    }

}

?>