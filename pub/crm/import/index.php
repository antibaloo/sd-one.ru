<?
require ($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");
if (\Bitrix\Main\Loader::includeModule('crm')) {
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
	fwrite($hd,"-----------------------------------------------------------------\n");
	if ($arResult['subject'] == '_Связь с установкой восстановлена'){
		$terminalId = $arResult['id'];
		$value = json_decode($arResult["value"], true);
    	$latitude = $value["latitude"];
    	$longitude = $value["longitude"];
		if ($latitude == 0 AND $longitude == 0){
			fwrite($hd, "переданы нулевые координаты\n");
			fwrite($hd,"-----------------------------------------------------------------\n");
			fclose($hd);
			echo "OK";
	    	return;
		}
		$entityTypeId = \CCrmOwnerType::Deal;
    	$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
    	$params = ["select" => ["ID", "UF_CRM_LATITUDE","UF_CRM_LONGITUDE",],"filter" =>["CATEGORY_ID" => 1,"UF_CRM_TERMINAL_ID" => $terminalId]];
    	$items = $factory->getItems($params);
    	if (!$items){
			fwrite($hd, "сделки направления 'устрановка' с id терминала ".$terminalId." не найдены\n");
			fwrite($hd,"-----------------------------------------------------------------\n");
			fclose($hd);
			echo "OK";
	    	return;
    	}
		$item = $factory->getItem($items[0]["ID"]);
    	$item->set('UF_CRM_LATITUDE',$latitude);
    	$item->set('UF_CRM_LONGITUDE',$longitude);
    	$operation = $factory->getUpdateOperation($item);
    	$operation->disableAllChecks();
    	$result = $operation->launch();
		if (!$result->isSuccess()){
			fwrite($hd, "при записи координат в сделку произошла ошибка: ".$$res->getErrorMessages()."\n");
			fwrite($hd,"-----------------------------------------------------------------\n");
			fclose($hd);
			return;
    	}
		$comment = array('event' => 'coords', 'data'=> array('latitude' => $latitude, 'longitude' => $longitude));
		\Bitrix\Crm\Timeline\CommentEntry::create(
			array(
				'TEXT' => json_encode($comment),
				'SETTINGS' => array('HAS_FILES' => 'N'), //cодержит ли файл комментарий
				'AUTHOR_ID' => 1, //ID пользователя, от которого будет добавлен комментарий
				'BINDINGS' => array(array('ENTITY_TYPE_ID' =>$entityTypeId, 'ENTITY_ID' => $item->getId())) // привязка к сущности CRM: ENTITY_TYPE_ID - тип сущности CRM (2 - Сделка), 'ENTITY_ID' - ID сделки в системе.
			));
	}
	fclose($hd);
	echo "OK";
}
?>