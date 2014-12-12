<?php
namespace MSEnt\Mail;

use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

class MailClient
{
    var $pop3_conn = false;
    var $resp = true;
    var $response_body = false;
    var $last_result = true;
    var $charset = false;
    var $cmd = false;

    function sendCommand($command)
    {
        //SSRF "filter"
        $command = preg_replace("/[\\n\\r]/", "", $command);

        fputs($this->pop3_conn, $command."\r\n");
        $this->cmd = $command;
        $this->resp = true;
    }

    function getResponse($bMultiline = false, $bSkipFirst = true)
    {
        if(!$this->resp) return false;
        $this->resp = false;

        socket_set_timeout($this->pop3_conn, 20);
        $res = rtrim(fgets($this->pop3_conn, 1024), "\r\n");
//		socket_set_blocking($this->pop3_conn, false);
//		socket_set_blocking($this->pop3_conn, true);

        $this->last_result = ($res[0]=="+");
        $this->response = $res;

        if($this->mailbox_id>0)
        {
/*            CMailLog::AddMessage(
                Array(
                    "MAILBOX_ID"=>$this->mailbox_id,
                    "STATUS_GOOD"=>($this->last_result?"Y":"N"),
                    "MESSAGE"=>"< ".$res
                )
            );*/
        }

        if($bMultiline && $res[0]=="+")
        {
            if($bSkipFirst)
                $res = "";
            else
                $res .= "\r\n";

            $s = fgets($this->pop3_conn, 1024);
            while(strlen($s)>0 && $s!=".\r\n")
            {
                if(substr($s, 0, 2)=="..")
                    $s = substr($s, 1);
                $res .= $s;
                $s = fgets($this->pop3_conn, 1024);
            }
        }
        $this->response_body = $res;

        /*echo '<pre>';
        echo 'CMD: '.$this->cmd.'<br>';
        echo 'RESPONSE: '.$this->response_body.'<br><br>';
        echo '</pre>';*/

        return $this->last_result;
    }

    function getResponseBody()
    {
        return $this->response_body;
    }

    function getResponseString()
    {
        return $this->response_body;
    }

    function check($server, $port, $use_tls, $login, $passw)
    {
        if($use_tls == 'Y' && strpos($server, 'tls://') === false)
            $server = 'tls://' . $server;

        $pop3_conn = &$this->pop3_conn;
        //$pop3_conn = fsockopen($server, $port, $errno, $errstr, COption::GetOptionInt("mail", "connect_timeout", 30));
        $pop3_conn = @fsockopen($server, $port, $errno, $errstr, 10); //TO-DO
        if(!$pop3_conn)
            return array(false, GetMessage("MAIL_CL_TIMEOUT")." $errstr ($errno)");

        $this->getResponse();
        $greeting = $this->getResponseString();

        $this->sendCommand("USER ".$login);
        if(!$this->getResponse())
            return array(false, GetMessage("MAIL_CL_ERR_USER").' ('.$this->getResponseString().')');
        $this->sendCommand("PASS ".$passw);
        if(!$this->getResponse())
            return array(false, GetMessage("MAIL_CL_ERR_PASSWORD").' ('.$this->getResponseString().')');

        $this->sendCommand("STAT");

        if(!$this->getResponse())
            return array(false, GetMessage("MAIL_CL_ERR_STAT").' ('.$this->getResponseString().')');

        $stat = trim($this->getResponseBody());
        $arStat = explode(" ", $stat);
        return array(true, $arStat[1]);
    }

    function checkMailBox($id)
    {
        $arMailBox = MailBoxTable::getRowById($id);
        if (!is_array($arMailBox)) {
            MailLog::error("MAILBOX_NOT_FOUND");
            return;
        }

        @set_time_limit(0);

        if ($arMailBox["SERVER"] == 'pop.gmail.com')
            $arMailBox["LOGIN"] = 'recent:' . $arMailBox["LOGIN"];

        $server = $arMailBox["SERVER"];
        if ($arMailBox['USE_TLS'] == 'Y' && strpos($server, 'tls://') === false)
        {
            $server = 'tls://' . $server;
        }

        $pop3_conn = &$this->pop3_conn;
        $pop3_conn = @fsockopen($server, $arMailBox["PORT"], $errno, $errstr, 30);

        if(!$pop3_conn || !is_resource($pop3_conn))
        {
            return MailLog::error("FAILED_CONNECT","$errstr ($errno)");
        }

        $use_md5 = $arMailBox["USE_MD5"] == "Y";
        $this->getResponse();
        $greeting = $this->getResponseString();

        if($use_md5=="Y" && preg_match("'(<.+>)'", $greeting, $reg))
        {
            $this->sendCommand("APOP ".$arMailBox["LOGIN"]." ".md5($reg[1].$arMailBox["PASSWORD"]));
            if(!$this->getResponse())
                return MailLog::error("ERR_AFTER_USER", $this->getResponseString());
        }
        else
        {
            $this->sendCommand("USER ".$arMailBox["LOGIN"]);
            if(!$this->getResponse())
                return MailLog::error("ERR_AFTER_USER", $this->getResponseString());
            $this->sendCommand("PASS ".$arMailBox["PASSWORD"]);
            if(!$this->getResponse())
                return MailLog::error("ERR_AFTER_PASS", $this->getResponseString());
        }

        $this->sendCommand("STAT");
        if(!$this->getResponse())
            return MailLog::error("ERR_AFTER_STAT", $this->getResponseString());
        $stat = trim($this->getResponseBody());
        $arStat = explode(" ", $stat);

        $mess_count = $arStat[1];
        if ($mess_count>0)
        {
            $this->sendCommand("UIDL");
            if(!$this->getResponse(true))
                return MailLog::error("ERR_AFTER_UIDL", $this->getResponseString());

            $uidl = $this->getResponseBody();
            preg_match_all("'([0-9]+)[ ]+?(.+)'", $uidl, $arUIDL_temp, PREG_SET_ORDER);

            $arUIDL = array();
            $cnt = count($arUIDL_temp);
            for ($i = 0; $i < $cnt; $i++)
                $arUIDL[md5($arUIDL_temp[$i][2])] = $arUIDL_temp[$i][1];

            $arOldUIDL = array();

            $dbRes = MailMessageTable::getList(array('select'=>array('MESSAGE_UID'),'filter'=>array('=MAILBOX_ID'=>$id)));
            while ($arRes = $dbRes->fetch())
            {
                $arOldUIDL[] = $arRes['MESSAGE_UID'];
                if (isset($arUIDL[$arRes['MESSAGE_UID']]))
                    unset($arUIDL[$arRes['MESSAGE_UID']]);
            }

            print_r($arUIDL);

            foreach($arUIDL as $msguid=>$msgnum)
            {
                    $this->getMessage($id, $msgnum, $msguid);

                /*if($arMAILBOX_PARAMS["DELETE_MESSAGES"]=="Y")
                {
                    $this->DeleteMessage($msgnum);
                    $this->deleted_mess_count++;
                }

                $this->new_mess_count++;
                if($arMAILBOX_PARAMS["MAX_MSG_COUNT"]>0 && $arMAILBOX_PARAMS["MAX_MSG_COUNT"]<=$this->new_mess_count)
                    break;*/
            }

        }

        $this->sendCommand("QUIT");
        if(!$this->getResponse())
            return MailLog::error("ERR_AFTER_QUIT", $this->getResponseString());

        fclose($pop3_conn);
        return true;
    }

    function getMessage($mailbox_id, $msgnum, $msguid)
    {
        $this->SendCommand("RETR ".$msgnum);
        if(!$this->GetResponse(true))
            return MailLog::error("ERR_AFTER_RETR", $this->GetResponseString());



        $message = $this->GetResponseBody();
        list($obHeader, $message_body_html, $message_body, $arMessageParts) = MailMessageTable::parseMessage($message, $this->charset);
        $arFields = array(
            "MAILBOX_ID" => $mailbox_id,
            "MESSAGE_UID" => $msguid,
            "HEADER" => $obHeader->strHeader,
            "FIELD_DATE" => \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime($obHeader->GetHeader("DATE"))+\CTimeZone::GetOffset()),
            "STATUS_ID"	=> "N", //new message
            "FIELD_FROM" => $obHeader->GetHeader("FROM"),
            "FIELD_REPLY_TO" => $obHeader->GetHeader("REPLY-TO"),
            "FIELD_TO" => $obHeader->GetHeader("TO"),
            "FIELD_CC" => $obHeader->GetHeader("CC"),
            "FIELD_BCC" => ($obHeader->GetHeader('X-Original-Rcpt-to')!=''?$obHeader->GetHeader('X-Original-Rcpt-to').($obHeader->GetHeader("BCC")!=''?', ':''):'').$obHeader->GetHeader("BCC"),
            "MSG_ID" => trim($obHeader->GetHeader("MESSAGE-ID"), " <>"),
            "IN_REPLY_TO" => trim($obHeader->GetHeader("IN-REPLY-TO"), " <>"),
            "FIELD_PRIORITY" => IntVal($obHeader->GetHeader("X-PRIORITY")),
            "SUBJECT" => $obHeader->GetHeader("SUBJECT"),
            "BODY" => rtrim($message_body)
        );

        var_dump(MailMessageTable::add($arFields));

        echo '<pre>';
        echo htmlspecialcharsbx(print_r($arFields,true));
        echo '</pre>';


        //$message_id = CMailMessage::AddMessage($mailbox_id, $message, $this->charset);
        //$connection = \Bitrix\Main\Application::getConnection();
        //date("d.m.Y H:i:s", strtotime($arFields["FIELD_DATE_ORIGINAL"])+CTimeZone::GetOffset())
        //$connection->getSqlHelper()->formatDate()
        //$arFields["FIELD_DATE"] = $DB->FormatDate(date("d.m.Y H:i:s", strtotime($arFields["FIELD_DATE_ORIGINAL"])+CTimeZone::GetOffset()), "DD.MM.YYYY HH:MI:SS", CLang::GetDateFormat("FULL"));

    }

}

?>
