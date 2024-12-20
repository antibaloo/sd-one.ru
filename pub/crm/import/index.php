<?
require ($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");
if (\Bitrix\Main\Loader::includeModule('crm')) {
	$hd = fopen(__DIR__."/log.txt", "a"); // отладочная запись в лог
	$arResult = array();
	$arResult = json_decode(file_get_contents('php://input'), true);
	fwrite($hd,"Получен запрос от хоста: ".$_SERVER['REMOTE_ADDR']."\n");
	fwrite($hd,"Время получения запроса: ".date('H:i:s d-m-Y',$_SERVER['REQUEST_TIME'])." (МСК)\n");
	fwrite($hd,"Запрос авторизован пользователем: ".$_SERVER['REMOTE_USER']."\n");
	fwrite($hd, print_r($arResult,1));
	fwrite($hd,"-----------------------------------------------------------------\n");
	
	// Ищем установку в Б24
	$terminalId = $arResult['id'];
    $dealFactory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
    $dealParams = ["select" => ["ID", "UF_CRM_LATITUDE","UF_CRM_LONGITUDE",],"filter" =>["CATEGORY_ID" => 1,"UF_CRM_TERMINAL_ID" => $terminalId]];
    $deals = $dealFactory->getItems($dealParams);
    if (!$deals){
		fwrite($hd, "Установка с id терминала ".$terminalId." не найдена в Б24\n");
		fwrite($hd,"-----------------------------------------------------------------\n");
		fclose($hd);
		echo "OK";
	   	return;
    }
	$equipmentId = (int)$deals[0]["ID"];
	// Ищем ответственного за установку
	$assignedById = (int)$deals[0]["ASSIGNED_BY_ID"];
	// Создаем заявку
	$leadFactory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Lead);
	$lead = $leadFactory->createItem();
	$lead
		->setAssignedById($assignedById)
		->setTitle($arResult["subject"])
		->setComments($arResult['message'])
		->set('UF_CRM_EQUIPMENT', $equipmentId);
	$context = new \Bitrix\Crm\Service\Context();
	$context->setUserId(1);
	$operation = $leadFactory->getAddOperation($lead,$context);
	$result = $operation->launch();
	if (!$result->isSuccess()){
		fwrite($hd, "при создании заявки произошла ошибка:\n");
		fwrite($hd, print_r($result->getErrorMessages(),1));
		fwrite($hd,"-----------------------------------------------------------------\n");
		fclose($hd);
		return;
	}
	// Если это ссобщение о выходе установки на связь
	if ($arResult['subject'] == 'Связь с установкой восстановлена'){
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

		$deal = $dealFactory->getItem($equipmentId); // Получаем установку по id найденному ранее
    	$deal->set('UF_CRM_LATITUDE',$latitude);
    	$deal->set('UF_CRM_LONGITUDE',$longitude);
    	$dealOperation = $dealFactory->getUpdateOperation($deal);
    	$dealOperation->disableAllChecks();
    	$result = $dealOperation->launch();
		if (!$result->isSuccess()){
			fwrite($hd, "при записи координат в установку произошла ошибка:\n");
			fwrite($hd, print_r($result->getErrorMessages(),1));
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
				'BINDINGS' => array(array('ENTITY_TYPE_ID' =>\CCrmOwnerType::Deal, 'ENTITY_ID' => $deal->getId()))
			));
	}
	fclose($hd);
	echo "OK";
}
?>