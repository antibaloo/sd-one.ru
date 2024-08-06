<?

require ($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('crm');
$hd = fopen(__DIR__."/log.txt", "a"); // отладочная запись в лог
$arResult = array();
$arResult = json_decode(file_get_contents('php://input'), true);
$lead = new CCrmLead;
$arFields = Array(
	"TITLE"	=>	$arResult['subject'],
	"ASSIGNED_BY_ID" => 1,
	"COMMENTS" => $arResult['message'],
	"STATUS_ID" => 'NEW',
	"OPENED" => "Y"
);
fwrite($hd,"Получен запрос от хоста: ".$_SERVER['REMOTE_ADDR']."\n");
fwrite($hd,"Время получения запроса: ".date('H:i:s d-m-Y',$_SERVER['REQUEST_TIME'])." (МСК)\n");
fwrite($hd,"Запрос авторизован пользователем: ".$_SERVER['REMOTE_USER']."\n");
fwrite($hd, print_r($arResult,1));
fwrite($hd, print_r($arFields,1));
$leadId = $lead->Add($arFields,true,array('CURRENT_USER'=>1));
if ($leadId) fwrite($hd, "Создан лид с идентификатором: ".$leadId."\n");
else fwrite($hd, "При создании лида произошла ошибка: ".$lead->LAST_ERROR."\n");

fclose($hd);
echo "OK";
?>