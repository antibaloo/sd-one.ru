<?
require ($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");
$severity = array(
	'0' => 'Не классифицированно',
	'1' => 'Информационный',
	'2' => 'Предупреждение',
	'3' => 'Средний',
	'4' => 'Высокий',
	'5' => 'Чрезвычайный'
);
$type = array(
	'connection' => 'Связь',
	'service' => 'Техническое обслуживание'
);
$interval = array(
	'none' =>'',
	'regular' => 'Регулярноe',
	'weekly' => 'Еженедельное',
	'monthly' => 'Ежемесячное'
);
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
	// Готовим данные для заявки
	if ($arResult['type'] == 'connection'){
		$title = "Связь с установкой потеряна";
		$comments = "Установка с id терминала ".$terminalId." уже 5 минут не выходит на связь.";
		if ($arResult['eventValue'] == 0){
			$title = "Установка вышла на связь.";
			$comments = "Установка с id терминала ".$terminalId." возобновила передачу данных.";
		}
	} else if ($arResult['type'] == 'service'){
		switch ($arResult['interval']){
			case 'regular':
				$title = $arResult['eventValue'] == 1 ? "Предупреждение о регилярном ТО (через 50 моточасов)" : "Регулярное техническое обслуживание";
				$comments = $arResult['description'];
				break;
			case "weekly":
				$title = "Еженедельное техническое обслуживание";
				$comments = $arResult['description'];
				break;
			case "monthly":
				$title = "Ежемесячное техническое обслуживание";
				$comments = $arResult['description'];
				break;
		}
	} else {
		$title = "Неизвестный тип сообщения";
		$comments = "Свяжитесь с администратором системы мониторинга!";
	}
	// Создаем заявку
	$leadFactory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Lead);
	$lead = $leadFactory->createItem();
	$lead
		->setAssignedById($assignedById)
		->setTitle($title)
		->setComments($comments)
		->set('UF_CRM_EVENT_ID', (int)$arResult['eventId'])
		->set('UF_CRM_EVENT_SEVERITY', $severity[$arResult['eventNSeverity']])
		->set('UF_CRM_EVENT_TYPE', $type[$arResult['type']])
		->set('UF_CRM_EVENT_INTERVAL', $interval[$arResult['interval']])
		->set('UF_CRM_EVENT_VALUE', (int)$arResult['eventValue'] == 1 ? 'Проблема' : 'Восстановление')
		->set('UF_CRM_EVENT_DATE', $arResult['eventValue'] ==1 ? $arResult['eventTime'].' '.$arResult['eventDate']: $arResult['eventRecoveryTime'].' '.$arResult['eventRecoveryDate'])
		->set('UF_CRM_EVENT_DURATION', $arResult['eventValue'] ==1 ? 0 : $arResult['eventDuration'])
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
	if ($arResult['type'] == 'connection' and $arResult['eventValue'] == 0){
		$value = json_decode($arResult["value"], true);
    	$latitude = $value["latitude"];
    	$longitude = $value["longitude"];
		$operatingHours = $value["can32bitr0"];
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
		$deal->set('UF_CRM_OPERATING_HOURS', $operatingHours);
    	$dealOperation = $dealFactory->getUpdateOperation($deal);
    	$dealOperation->disableAllChecks();
    	$result = $dealOperation->launch();
		if (!$result->isSuccess()){
			fwrite($hd, "при записи данных в установку произошла ошибка:\n");
			fwrite($hd, print_r($result->getErrorMessages(),1));
			fwrite($hd,"-----------------------------------------------------------------\n");
			fclose($hd);
			return;
    	}
		//Добавляем комментарий для истории (координаты)
		$comment = array('event' => 'coords', 'data'=> array('latitude' => $latitude, 'longitude' => $longitude));
		\Bitrix\Crm\Timeline\CommentEntry::create(
			array(
				'TEXT' => json_encode($comment),
				'SETTINGS' => array('HAS_FILES' => 'N'), //cодержит ли файл комментарий
				'AUTHOR_ID' => 1, //ID пользователя, от которого будет добавлен комментарий
				'BINDINGS' => array(array('ENTITY_TYPE_ID' =>\CCrmOwnerType::Deal, 'ENTITY_ID' => $deal->getId()))
		));
		//Добавляем комментарий для истории (моточасы)
		$comment = array('event' => 'operating_hours', 'data'=> $operatingHours);
		\Bitrix\Crm\Timeline\CommentEntry::create(
			array(
				'TEXT' => json_encode($comment),
				'SETTINGS' => array('HAS_FILES' => 'N'), //cодержит ли файл комментарий
				'AUTHOR_ID' => 1, //ID пользователя, от которого будет добавлен комментарий
				'BINDINGS' => array(array('ENTITY_TYPE_ID' =>\CCrmOwnerType::Deal, 'ENTITY_ID' => $deal->getId()))
		));
	}
	// Если сообщение о регулярном обслуживании
	if ($arResult['type'] == 'service' and $arResult['interval'] == 'regular'){
		$operatingHours = $arResult['value'];
		$deal = $dealFactory->getItem($equipmentId); // Получаем установку по id найденному ранее
		$deal->set('UF_CRM_OPERATING_HOURS', $operatingHours);
		$dealOperation = $dealFactory->getUpdateOperation($deal);
		$dealOperation->disableAllChecks();
		$result = $dealOperation->launch();
		if (!$result->isSuccess()){
			fwrite($hd, "при записи данных в установку произошла ошибка:\n");
			fwrite($hd, print_r($result->getErrorMessages(),1));
			fwrite($hd,"-----------------------------------------------------------------\n");
			fclose($hd);
			return;
		}
		//Добавляем комментарий для истории (моточасы)
		$comment = array('event' => 'operating_hours', 'data'=> $operatingHours);
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