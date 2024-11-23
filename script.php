<?

function sendEvent(array $data): void
{
    $jsonData = json_encode($data);
    $ch = curl_init('https://ssec.sendsay.ru/general/ssec/v100/json/x_1705408478391700/');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: sendsay apikey=YOUR_API_KEY'
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        error_log(curl_error($ch));
    }
    curl_close($ch);
}

function saveLastProcessedOrderId($orderId): void
{
    file_put_contents(__DIR__ . '/lastProcessedOrderId.txt', $orderId);
}

function getLastProcessedOrderId(): int
{
    if (file_exists(__DIR__ . '/lastProcessedOrderId.txt')) {
        return (int) file_get_contents(__DIR__ . '/lastProcessedOrderId.txt');
    }
    return 0;
}

function sendCompleteOrders(): void
{
    if (CModule::IncludeModule("sale") && CModule::IncludeModule("catalog") && CModule::IncludeModule("iblock")) {
        $lastProcessedOrderId = getLastProcessedOrderId();
        $arFilter = array(
            "STATUS_ID" => "F",
            "<=DATE_INSERT" => "12.03.2024 23:59:59",
            ">ID" => $lastProcessedOrderId
        );

        $dbOrders = CSaleOrder::GetList(
            array("DATE_INSERT" => "ASC"),
            $arFilter
        );

        $orderCount = 0;
        $batchSize = 10;
        $batch = [];

        while ($arOrder = $dbOrders->Fetch()) {
            try {
                $dbUser = CUser::GetByID($arOrder["USER_ID"]);
                if ($arUser = $dbUser->Fetch()) {
                    $userEmail = $arUser["EMAIL"];
                }

                $trueDate = DateTime::createFromFormat('d.m.Y H:i:s', $arOrder["DATE_INSERT"]);

                $orderData = [
                    "email" => $userEmail,
                    "addr_type" => "email",
                    "transaction_id" => $arOrder["ID"],
                    "transaction_dt" => $trueDate->format('Y-m-d H:i:s'),
                    "transaction_sum" => $arOrder["PRICE"],
                    "transaction_discount" => $arOrder["DISCOUNT_VALUE"] > 0 ? $arOrder["DISCOUNT_VALUE"] : 0,
                    "transaction_status" => 1,
                    "items" => [],
                    "event_type" => 1,
                    "update" => 1
                ];

                $dbBasketItems = CSaleBasket::GetList(
                    array("NAME" => "ASC"),
                    array("ORDER_ID" => $arOrder["ID"])
                );

                while ($arBasketItem = $dbBasketItems->Fetch()) {
                    // Получение раздела товара
                    $arSections = [];
                    $res = CIBlockElement::GetElementGroups($arBasketItem["PRODUCT_ID"], true);
                    while ($arSection = $res->Fetch()) {
                        $arSections[] = $arSection["NAME"];
                        $arSectionId = $arSection["ID"];
                    }
                    $sections = implode(', ', $arSections);

                    $orderData["items"][] = [
                        'id' => $arBasketItem["PRODUCT_ID"],
                        'qnt' => $arBasketItem["QUANTITY"],
                        'price' => $arBasketItem["PRICE"],
                        'name' => $arBasketItem["NAME"],
                        'category' => $sections,
                        'category_id' => $arSectionId
                    ];
                }

                $batch[] = $orderData;
                $orderCount++;

                if (count($batch) >= $batchSize) {
                    sendEvent($batch);
                    $batch = [];
                }

                saveLastProcessedOrderId($arOrder["ID"]);
            } catch (Exception $e) {
                error_log("Error processing order ID {$arOrder["ID"]}: " . $e->getMessage());
                continue; // продолжить со следующим заказом
            }
        }

        // Отправка оставшихся заказов
        if (!empty($batch)) {
            sendEvent($batch);
        }

        echo "Общее число заказов: " . $orderCount;
    } else {
        error_log("Не удалось подключить модуль sale, catalog или iblock.");
    }
}

//sendCompleteOrders();

?>
