<?
namespace MSEnt\Mail;

use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

class MailBoxTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_ms_mail_mailbox';
    }

    public static function getMap()
    {
        return array(
            new Entity\IntegerField('ID',array("primary"=>true,"autocomplete"=>true,"title"=>"ID")),
            new Entity\BooleanField('ACTIVE',array("values"=>array("N","Y"),"default_value"=>"Y","title"=>Loc::getMessage("MSENT_MAILBOX_ACTIVE"))),
            new Entity\StringField('SERVER',array("size"=>255,"required"=>true,"title"=>Loc::getMessage("MSENT_MAILBOX_SERVER"))),
            new Entity\IntegerField('PORT',array("required"=>true,"title"=>Loc::getMessage("MSENT_MAILBOX_PORT"))),
            new Entity\BooleanField('USE_TLS',array("values"=>array("N","Y"),"default_value"=>"N","title"=>Loc::getMessage("MSENT_MAILBOX_USETLS"))),
            new Entity\BooleanField('USE_MD5',array("values"=>array("N","Y"),"default_value"=>"N","title"=>Loc::getMessage("MSENT_MAILBOX_USEMD5"))),
            new Entity\StringField('LOGIN',array("size"=>255,"required"=>true,"title"=>Loc::getMessage("MSENT_MAILBOX_LOGIN"))),
            new Entity\StringField('PASSWORD',array(
                "size"=>255,
                "title"=>Loc::getMessage("MSENT_MAILBOX_PASSWORD"),
                "save_data_modification" => function () {
                    return array(
                        function ($value) {
                            return base64_encode($value);
                        }
                    );
                },
                'fetch_data_modification' => function () {
                    return array(
                        function ($value) {
                            return base64_decode($value);
                        }
                    );
                }
            )),
            new Entity\StringField('CHARSET',array("size"=>255,"default_value"=>"windows-1251","title"=>Loc::getMessage("MSENT_MAILBOX_CHARSET"))),
        );
    }
}

?>