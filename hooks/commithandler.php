<?
define("NOT_CHECK_PERMISSIONS", true);
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

// Логировать или нет
define("LOG_DATA", true);
define("LOG_DATA_PREFIX", "[WebHook gitlab]: ");

define("MATRA_ID", 1846);    // ID бота Марта
define("COMMIT_LIST_ID", 62);    // ID списка для ленты коммитов
define("TRIM_TASK_TAG", false); // Если хочется удалять из коммента тег с номером задачи, надо поставить true
define("BX_CODEPAGE_CP1251", false);    // Если битрикс установлен в cp1251, то должно быть true

if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "hook called...", "tasks");

// Получаем входной поток данных от гитлаба
$handle = fopen("php://input", "rb");
while (!feof($handle)) {
    $http_raw_post_data .= fread($handle, 8192);
}

fclose($handlle);

$arData = json_decode($http_raw_post_data, true);


if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "gitlab data: " . print_r($arData, true), "tasks");

// Получаем id пользователя в битриксе
// Clean id from whitespaces. You don't need this.
$arData['user_email'] = $arData['user_email'] ? preg_replace('/\s+/', '', $arData['user_email']) : null;
if (!isset($arData["user_email"]) || strlen($arData['user_email']) <= 0) {
    return;
}

if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "gitlab user email: " . $arData['user_email'], "tasks");

$userFound = false;

$rsUser = CUser::GetList(($by = "ID"), ($order = "DESC"), array("EMAIL" => $arData["user_email"]));
$arUser = $rsUser->Fetch();
if (!$arUser) {
    // Если пользователь не найден в битриксе, используем id бота Марта
    $userID = MATRA_ID;
    if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "gitlab user #" . $arData['user_id'] . " not found in bitrix. Set default id for bot Marta: " . $userID, "tasks");
} else {
    $userID = $arUser["ID"];
    $userFound = true;
}

if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "bitrix user id: " . $userID, "tasks");

// Авторизуем пользователя в битириксе, чтобы можно было написать коммент от его имени
$GLOBALS["USER"]->Authorize($userID);


// Получаем id задачи
$TASK_ID = false;
if (preg_match("/task([0-9]+)/i", $arData["repository"]["name"], $r))
    $TASK_ID = $r[1];
elseif (preg_match("/task([0-9]+)/i", $arData["repository"]["description"], $r))
    $TASK_ID = $r[1];
elseif (preg_match("/task([0-9]+)/i", $arData["ref"], $r))
    $TASK_ID = $r[1];

CModule::IncludeModule("tasks");
CModule::IncludeModule("forum");

$branch = $arData["ref"];
$branch = str_replace("refs/heads/", "", $branch);

foreach ($arData["commits"] as $arCommit) {

    $taskFound = false;    // Задача найдена в битриксе
    $arTask = array();

    // Сообщение коммита
    $message = $arCommit["message"];
    if (BX_CODEPAGE_CP1251) $message = utf8win1251($message);
    if (TRIM_TASK_TAG) $message = str_replace($r[0], "", $message);
    if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "commit message: " . $message, "tasks");


    if (preg_match("/task([0-9]+)/i", $message, $r)) {
        $TASK_ID = $r[1];
    }

    if ($TASK_ID > 0) {
        // Если id таски передан, пробуем найти задачу и добавить туда коммент
        if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "bitrix task id from commit: " . $TASK_ID, "tasks");

        // Ищем таску среди всех таск в битрикс (специально указываем в качестве пользователя 1)
        if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Find task # " . $TASK_ID . " for all users", "tasks");
        $oTask = CTaskItem::getInstance($TASK_ID, 1);

        if ($oTask) {

            if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Task #" . $TASK_ID . " is found in bitrix!", "tasks");
            $taskFound = true;

            $arTask = $oTask->getData();

            // Таска найдена, проверяем, есть ли у пользователя права доступа к ней
            if (!$oTask->isActionAllowed("ACTION_ACCEPT")) {
                // Добавляем пользователя в соисполнители
                if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "User #" . $userID . " not access to task #" . $TASK_ID, "tasks");

                $oTask->Update(array("ACCOMPLICES" => array_merge($arTask["ACCOMPLICES"], array($userID))));
            } else {
                if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "User #" . $userID . " has access to task #" . $TASK_ID, "tasks");
            }

        } else {
            // Таска не найдена, создаем
            if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Task #" . $arTask["ID"] . " is found in bitrix!", "tasks");
        }

    } else {
        // В коммите не передан id задачи
        if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Task id not found in commit", "tasks");
    }

    if ($taskFound) {

        $commentMessage =
            "<b>Repository:</b> [URL=" . $arData["repository"]["git_http_url"] . "]" . $arData["repository"]["name"] . "[/URL] \n"
            . "<b>Commit id:</b> [URL=" . $arCommit["url"] . "]" . $arCommit["id"] . "[/URL] \n"
            . "<b>Author:</b> " . $arCommit["author"]["name"] . "\n"
            . "<b>Branch:</b> " . $branch . "\n"
            . "<b>Comment:</b>\n" . $message;

        $commentID = CTaskComments::add($TASK_ID, $userID, $commentMessage);

        if ($commentID > 0) {
            if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "add comment to task result: " . print_r($commentID, true), "tasks");
        } else {
            if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Comment for task #" . $TASK_ID . " not added by user #" . $userID . "...", "tasks");
        }
    } else {
        if ($userFound && ($userID != MATRA_ID)) {
            // Если задача не найдена, то создаем новую
            if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Create new task...", "tasks");

            $taskMessage =
                "<b>Repository:</b> <a href='" . $arData["repository"]["git_http_url"] . "' target='_BLANK'>" . $arData["repository"]["name"] . "</a> <br>"
                . "<b>Commit id:</b> <a href='" . $arCommit["url"] . "' target='_BLANK'>" . $arCommit["id"] . "</a> <br>"
                . "<b>Author:</b> " . $arCommit["author"]["name"] . "<br>"
                . "<b>Branch:</b> " . $branch . "<br>"
                . "<b>Comment:</b><br>" . $message;

            $arTask = Array(
                "TITLE" => "Commit #" . substr($arCommit["id"], 0, 9),
                "DESCRIPTION" => $taskMessage,
                "RESPONSIBLE_ID" => $userID,
                "CREATED_BY" => $userID
            );

            $oTask = CTaskItem::Add($arTask, $userID);
            if ($oTask > 0) {
                // Информация о созданной таске
                $arTask = $oTask->getData();
                if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Created new task: " . print_r($arTask, true), "tasks");

                $TASK_ID = $taskData["ID"];

                // Определим руководителя сотрудника и добавим в задачу
                $userManagerID = getBitrixUserManager($userID);
                if ($userManagerID) {
                    if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Manager for user #" . $userID . " is #" . print_r($userManagerID, true), "tasks");
                    $oTask->Update(array("AUDITORS" => array_merge($arTask["AUDITORS"], array($userManagerID[0]))));
                } else {
                    if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Manager for user #" . $userID . " not defined...", "tasks");
                }


            } else {
                if ($e = $APPLICATION->GetException()) {
                    if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Created new task error: " . print_r($e->GetString(), true), "tasks");
                }
            }

        }
    }

    // Сохраним коммит в общий список коммитов
    if (COMMIT_LIST_ID > 0) {

        if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Task data: " . print_r($arTask, true), "tasks");

        $el = new CIBlockElement;

        $PROP = array(
            "COMMIT_URL" => "<a href='" . $arCommit["url"] . "' target='_BLANK'>" . $arCommit["id"] . "</a>",
            "TASK_URL" => "<a href='/company/personal/user/" . $userID . "/tasks/task/view/" . $TASK_ID . "/' target='_BLANK'>" . $arTask["TITLE"] . " (" . $arTask["ID"] . ")</a>",
            "AUTHOR_NAME" => $arCommit["author"]["name"],
            "AUTHOR_EMAIL" => $arCommit["author"]["email"],
            "BRANCH" => $branch,
            "REPOSITORY" => "<a href='" . $arData["repository"]["git_http_url"] . "' target='_BLANK'>" . $arData["repository"]["name"] . "</a>"
        );

        $arLoadProductArray = Array(
            "CREATED_BY" => $userID,
            "MODIFIED_BY" => $userID,
            "IBLOCK_ID" => COMMIT_LIST_ID,
            "PROPERTY_VALUES" => $PROP,
            "NAME" => $arCommit["id"],
            "ACTIVE" => "Y",
            "PREVIEW_TEXT" => $message,
        );

        if ($LIST_ITEM_ID = $el->Add($arLoadProductArray)) {
            if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Create new list item #" . $LIST_ITEM_ID, "tasks");
        } else {
            if (LOG_DATA) AddMessage2Log(LOG_DATA_PREFIX . "Error creating new list item: " . print_r($el->LAST_ERROR, true), "tasks");
        }

    }

    // Обнуляем id таски, иначе если будет несколько коммитов с указанием задач и без указания, 
    // то вместо создания задач будут создаваться комменты
    $TASK_ID = false;
}


/***
 *
 * Получить руководителя пользователя
 * Возвращает массив с айдишниками руководителей
 *
 */
function getBitrixUserManager($user_id){
    if (CModule::IncludeModule("intranet") && ($user_id > 0)) {
        return array_keys(CIntranetUtils::GetDepartmentManager(CIntranetUtils::GetUserDepartments($user_id), $user_id, true));
    } else {
        return false;
    }
}


require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_after.php");
?>
