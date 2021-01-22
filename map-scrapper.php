<?php
// подключение функций пролога
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");


require_once "phpQuery.php"; // Библиотека парсера

CModule::IncludeModule("main"); // CFile
CModule::IncludeModule("iblock"); // CIBlockElement


/**
 * @param $request string ссылка на страницу магазина
 * @return string получаем бооольшой html страницы
 */
function getRequestResult($request)
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $request);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $server_output = curl_exec($ch);
  curl_close($ch);
  return $server_output;
}

/**
 * Функция парсит и добавляет комментарии в инфоблок
 * @param $STORE_ID id магазина
 * @param $BLOCK_ID id инфблока с комментариями (куда записываем)
 *
 */
function parseAndAdd($STORE_ID, $BLOCK_ID)
{
  $org_url = 'https://yandex.ru/maps/org/' . $STORE_ID . '/reviews/';
  $html = getRequestResult($org_url);

  $doc = phpQuery::newDocument($html);

  $counter = 0; // Счетчик добавленных элементов

  foreach ($doc->find('.business-review-view__info') as $post) {
    $post = pq($post);
    $name = $post->find('.business-review-view__author span')->text();
    $imgUrl = $post->find('.business-review-view__author meta')->attr('content');
    $text = $post->find('.business-review-view__body-text')->text();
    $date = $post->find('.business-review-view__date meta')->attr(content);
    // Приводим дату в соответствующий формат для базы
    $date = date("d.m.Y m:h:s", strtotime($date));
    // Проверяем дату на ошибки
    if (!CDatabase::IsDate($date))
      echo "Ошибка. Неверный формат даты.";

    $rating = $post->find('.business-rating-badge-view__star._size_m')->count();

    // Прогоняем количество звезд и присваиваем ID свойства списка.
    switch ($rating) {
      case 5:
        $rating = 110; // id 110 = 5* в свойстве списка
        break;
      case 4:
        $rating = 111; // id 111 = 4* в свойстве списка
        break;
      case 3:
        $rating = 112; // id 112 = 3* в свойстве списка
        break;
      case 2:
        $rating = 113; // id 113 = 2* в свойстве списка
        break;
      case 1:
        $rating = 114; // id 114 = 1* в свойстве списка
        break;
      default:
        $rating = 110; // по дефолту вернем 5 звезд
        break;
    }


    // FOR DEBUG
    // $parsedData[]['name'] = $name;
    // $parsedData[]['imgUrl'] = $imgUrl;
    // $parsedData[]['text'] = $text;
    // $parsedData[]['date'] = $date;
    // $parsedData[]['rating'] = $rating;


    $el = new CIBlockElement;

    /**
     * Параметры для перевода в транслит - дальнейший slug элемента
     */
    $arTransParams = [
      "max_len" => 100,
      "change_case" => 'L', // 'L' - toLower, 'U' - toUpper, false - do not change
      "replace_space" => '-',
      "replace_other" => '-',
      "delete_repeat_replace" => true,
    ];

    $fields = [
      'ACTIVE' => "Y",
      'IBLOCK_ID' => $BLOCK_ID, //id 23 - Инфоблок отзывы яндекс карты
      "IBLOCK_SECTION_ID" => false, // Лежим в корне
      "NAME" => $name,
      "CODE" => CUtil::translit($name, "ru", $arTransParams),
      "PREVIEW_TEXT" => mb_strimwidth($text, 0, 100, "..."),
      "DETAIL_TEXT" => $text,
      //"DETAIL_PICTURE" => $imgUrl ? CFile::MakeFileArray($imgUrl) : "",
      //'CREATED_BY' => '1',
      //'MODIFIED_BY' => '1',
      "PROPERTY_VALUES" => [
        "COMPANY_ID" => $STORE_ID,
        "FEEDBACK_RATING" => $rating // ID 5 звезд
      ],
      'ACTIVE_FROM' => $date, // Начало активности
      'DATE_CREATE' => $date, // Дата создания
    ];

    if ($PRODUCT_ID = $el->Add($fields)) {
      echo 'Добавлен элемент, ID: ' . $PRODUCT_ID . '<br />';
      $counter++;
    } else {
      echo "Error[" . $PRODUCT_ID . "]: " . $el->LAST_ERROR . '<br />';
    }

  }


//  CEventLog::Add([
//    "SEVERITY" => "SECURITY",
//    "AUDIT_TYPE_ID" => "MAP_PARSER",
//    "MODULE_ID" => $BLOCK_ID,
//    "ITEM_ID" => "",
//    "DESCRIPTION" => "[PARSER] Получил отзывы с yandex карт для магазина с ID = $STORE_ID. Добавлено $counter элементов"
//  ]);

  // DEBUG - выводим сколько элементов обработано и добавлено.
  echo $STORE_ID . ' распаршен и добавлен. Добавлено '. $counter. ' элементов <br>';

}

// ID всех магазинов, свойтво инфоблока - COMPANY_ID
// 71168074839 - Deerkalyan Отрадное
// 1421081142 - Deerkalyan Бескудниково
// 32017561318 - Deerkalyan Тимирязевская
// 44962562108 - Deerkalyan Основной склад Алтуфьево
// 130263912071 - Deerkalyan Жулебино Лермонтовский проспект
// 126462893408 - Deerkalyan Бибирево
// 180068942046 - Deerkalyan Мытищи
// 225167910639 - Deerkalyan Ухта
// 242345069382 - Deerkalyan Марьино
// 203817317905 - DeerKalyan Люблино


$places_ids = []; // Храним ID всех точек на карте

$arSelect = [
  "NAME",
  "PROPERTY_COMPANY_ID"
];
$arFilter = [
  "IBLOCK_ID" => 11,  // ID блока КОНТАКТЫ
  "ACTIVE" => "Y",
];
$resp = CIBlockElement::GetList([], $arFilter, false, Array("nPageSize"=>50), $arSelect);
while($ob = $resp->GetNextElement())
{
 $arFields = $ob->GetFields(); // Получаем значения полей элемента
 $places_ids[] = $arFields['PROPERTY_COMPANY_ID_VALUE']; // Заполняем наш массив ID мест
}


// Запускаем парсинг каждого ID шника
foreach ($places_ids as $id)
  parseAndAdd($id, 23);


?>

