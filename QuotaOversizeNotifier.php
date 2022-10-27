<?php
/**
 * Класс предназначен для проверки заполненности размера диска группы на портале Bitrix24,
 * отсылает уведомление в ленту новостей группы или пользователя (владельца диска)
 * при превышении заполнения более чем на установленное значение в процентах (константа ALLOWED_SIZE)
 */

namespace lib;

use Bitrix\Disk\Internals\StorageTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Socialnetwork\WorkgroupTable;
use gpi\Helper\Disk;
use Bitrix\Main\Loader;
use Bitrix\Main\Entity;
use Bitrix\Highloadblock as HL;

Loader::includeModule('blog');
Loader::includeModule('socialnetwork');
Loader::includeModule('socialservices');
Loader::includeModule('highloadblock');

class QuotaOversizeNotifier {
    const ALLOWED_SIZE         = 90;    // разрешенная заполненность диска в процентах без отправки уведомления
    const ADMIN                = 1;
    const ADMIN_BLOG_ID        = 105;
    const BLOG_PUBLISH_STATUS  = "P";
    const HIGHLOAD_IDENTIFIER  = 61;
    private static $highloadEntityDataClass;

    /**
     * QuotaOversizeNotifier constructor.
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public function __construct()
    {
        if( !self::$highloadEntityDataClass ) {
            $hlbl = self::HIGHLOAD_IDENTIFIER;
            $hlblock = HL\HighloadBlockTable::getById( $hlbl )->fetch();
            $entity = HL\HighloadBlockTable::compileEntity( $hlblock );
            self::$highloadEntityDataClass = $entity->getDataClass();
        }
    }

    /**
     * метод получает все диски сущностей Group и User
     *
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function getDisks() {
        $dbDisks = StorageTable::getList([
            'select' => [
                'ID',
                'NAME',
                'ENTITY_ID',
                'ENTITY_TYPE'
            ],
            'filter' => [
                'ENTITY_TYPE' => ['%Group','%User']
            ]
        ]);

        while($disk = $dbDisks -> Fetch()) {
            $disks[$disk['ID']] = $disk;
        }

        return $disks;
    }

    /**
     * метод возвращает диски, которые переполнены более чем на ALLOWED_SIZE %
     *
     * @return array
     */
    private function getOversizedDisks() {
        $compareDisksResult = Disk::getDiskQuotaCompare(['>DISK_SIZE' => 0]);

        foreach($compareDisksResult as $singleDisk) {
            if($singleDisk['PERCENT'] > self::ALLOWED_SIZE) {
                $oversizedDisksResult[$singleDisk['ID']] = $singleDisk;
            }
        }

        return $oversizedDisksResult;
    }

    /**
     * метод сопоставляет полученные переполненные диски с идентификаторами блогов и пользователей
     * и возвращает массив дисков, владельцам которых будем отправлять уведомление в ленту новостей
     *
     * @return array
     * ID идентификатор диска
     * NAME наименование диска
     * ENTITY_ID идентификатор либо пользователя, либо группы
     * ENTITY_TYPE тип сущности владельца диска, либо пользователь, либо группа
     */
    private function matchDisksWithBlogsUsers() {
        $matchedDisksWithBlogsUsers = array();
        $oversizedDisks = $this->getOversizedDisks();
        $disksBlogsUsers = $this->getDisks();

        foreach($oversizedDisks as $diskId => $diskData) {
            // проверять будем переполнение только для дисков групп
            // в дальнейшем если понадобится проверять так же и диски пользователей достаточно будет просто убрать это условие $disksBlogsUsers[$diskId]['ENTITY_TYPE'] === "Bitrix\Disk\ProxyType\Group"
            if( $disksBlogsUsers[$diskId]['ENTITY_TYPE'] === "Bitrix\Disk\ProxyType\Group" ) {
                $fullData = array_merge($diskData, $disksBlogsUsers[$diskId]);

                // проверим выслано ли уведомление по этому диску уже
                $notificationSent = $this->checkIfNotificationToThisEntityAlreadySent($fullData);
                if( !$notificationSent ) {
                    $matchedDisksWithBlogsUsers[] = $fullData;
                }
            }
        }

        return $matchedDisksWithBlogsUsers;
    }

    /**
     * метод проверяет в highloadblock отправлено ли уже уведомление этой сущности (группа или юзер) о том, что квота на его диск практически исчерпана
     *
     * @param $singleEntity
     * @return false
     */
    private function checkIfNotificationToThisEntityAlreadySent( $fullData ) {
        $notificationSent = false;

        $notificationRecord = (self::$highloadEntityDataClass)::getList([
            'select' => ['*'],
            'filter' => [
                'UF_DISK_ID' => $fullData['ID'],
                'UF_ENTITY_ID' => $fullData['ENTITY_ID'],
                'UF_ENTITY_TYPE' => '%Group'
            ]
        ])->fetchAll();
        if( count($notificationRecord)>0 ) {
            $notificationSent = true;
        }
        return $notificationSent;
    }

    /**
     * метод добавлят запись в highloadblock о том, что в живую ленту этой сущности отправлено уведомление о том, что квота на диск исчерпана на 90%
     *
     * @param $data
     * @return int
     */
    private function createNotificationRecordInHighloadBlock( $fullData ) {
        $recordId = 0;
        $hlbl = self::HIGHLOAD_IDENTIFIER;
        $hlblock = HL\HighloadBlockTable::getById($hlbl)->fetch();
        $entity = HL\HighloadBlockTable::compileEntity($hlblock);
        $entityDataClass = $entity->getDataClass();

        $fields = [
            "UF_DISK_ID" => $fullData['ID'],
            "UF_ENTITY_ID" => $fullData['ENTITY_ID'],
            "UF_ENTITY_TYPE" => $fullData['ENTITY_TYPE']
        ];

        if($dbResult = $entityDataClass::add($fields)) {
            $recordId = $dbResult->getId();
        }

        return $recordId;
    }

    /**
     * Метод отправляет уведомление в ленту новостей конкретной сущности с идентификтаором $singleEntity['ENTITY_ID']
     *
     * @param $entityType
     * @param $entityId
     * @return false|int|null
     * @throws \Bitrix\Main\LoaderException
     */
    private function sendNotificationIntoNewsFeed( $singleEntity ) {
        global $DB;
        global $APPLICATION;
        Loader::includeModule("socialnetwork");
        Loader::includeModule("blog");

        // определим сущность, в ленту которого будем отправлять уведомление, в соответствии с этим зададим права, без них уведомление не отобразится в лент
        switch($singleEntity['ENTITY_TYPE']) {
            case "Bitrix\Disk\ProxyType\User":
                $entityRight = ["U" . $singleEntity['ENTITY_ID']];
                $entityNameToBeInformed = "пользователя " . $singleEntity['NAME'];
                break;
            case "Bitrix\Disk\ProxyType\Group":
                $entityRight = [
                    "SG"  . $singleEntity['ENTITY_ID'],
                    "OSG" . $singleEntity['ENTITY_ID'] . "_L",
                    "SG"  . $singleEntity['ENTITY_ID'] . "_A",
                    "SG"  . $singleEntity['ENTITY_ID'] . "_E",
                    "SG"  . $singleEntity['ENTITY_ID'] . "_K",
                    "SA",
                    "U1"
                ];
                $entityNameToBeInformed = "группы " . $singleEntity['NAME'];
                break;
        }

        $availableSpace = $singleEntity['SIZE_LIMIT_POW'] . "Гб";
        $currentCapacity = $singleEntity['DISK_SIZE_POW'] . "Гб";

        $notificationTitle = "Занято более " . self::ALLOWED_SIZE . "% дискового пространства " . $entityNameToBeInformed . ".";
            $diskQuotaEhnanceLink = "<a target='_blank' href='/cpgp/services/disk.quota/'>Увеличить размер диска группы</a>";
        $notificationDetailText = "Объем доступного пространства " . $availableSpace . ". Текущий объем " . $currentCapacity . ". Для увеличения дискового пространства необходимо подать соответствующее обращение через сервис " . $diskQuotaEhnanceLink . ".";

        // подготвка полей поста
        $arFields = array(
            "TITLE" => $notificationTitle,
            "DETAIL_TEXT" => $notificationDetailText,
            "BLOG_ID" => self::ADMIN_BLOG_ID,
            "AUTHOR_ID" => self::ADMIN,
            "PUBLISH_STATUS" => self::BLOG_PUBLISH_STATUS,
            "PATH" => "/company/personal/user/1/blog/#post_id#/",
            "SOCNET_RIGHTS" => $entityRight
        );

        // если пост в таблице b_blog_post создан $ID отправляем сообщение в ленту
        if( $ID = \CBlogPost::Add($arFields) ) {
            // подготвка полей уведомления в ленте
            $arEvent = array (
                'EVENT_ID'     => 'blog_post',
                '=LOG_DATE'    => 'now()',
                'TITLE_TEMPLATE' => 'это в title template уведомления Сообщение о превышении размера диска, более ' . self::ALLOWED_SIZE,
                'TITLE'    => 'это в TITLE уведомления Сообщение о превышении размера диска, более ' . self::ALLOWED_SIZE . "Для сущности типа: " . $singleEntity['ENTITY_ID'] . " c идентификатором " . $singleEntity['ENTITY_ID'] . ". Если это пользователь, то можно зайти по ссылке на его блог и увидеть уведомление это в его блоге, если это группа, то нужно найти OWNER_ID это группы, авторизоваться под ним и уже зайти в саму группу-социальную сеть и убедиться, то уведомление появилось.",
                'MESSAGE'  => 'ЭТО ВООБЩЕ В MESSAGE -  --- -- Превышен размера диска, более ' . self::ALLOWED_SIZE,
                'TEXT_MESSAGE'  => 'ЭТО В СВОЙСТВЕ TEXT MESSAGE Превышен размера диска, более ' . self::ALLOWED_SIZE,
                'MODULE_ID'     => 'blog',
                'CALLBACK_FUNC' => false,
                'SOURCE_ID'     => $ID,
                'ENABLE_COMMENTS'  => 'Y',
                'RATING_TYPE_ID'   => 'BLOG_POST',
                'RATING_ENTITY_ID' => $ID,
                'ENTITY_TYPE' => "U",
                "USER_ID" => self::ADMIN,
                'ENTITY_ID'   => self::ADMIN,
                'URL' => '/company/personal/user/1/blog/'.$ID.'/',
            );

            // если уведомление создано - зададим права и отобразим сообщение в ленте
            if( $LOG_ID = \CSocNetLog::Add($arEvent) ) {
                if(intval($LOG_ID)) {
                    \CSocNetLog::Update($LOG_ID, array('TMP_ID' => $LOG_ID));
                    // необходимо добавить права на этот пост, чтобы уведомление отобразилось в ленте новостей
                    \CSocNetLogRights::Add($LOG_ID, array($entityRight));
                    \CSocNetLog::SendEvent($LOG_ID, 'SONET_NEW_EVENT');

                    // создадим запись в highload блоке #61, если запись по этому диску, сущности - владельцу существует значит уведомление отправлено
                    if($recordId = $this->createNotificationRecordInHighloadBlock( $singleEntity )) {
                        $some = "";
                    } else {
                        $some = "";
                    }

                    return $LOG_ID;
                }
            }

            return null;
        } else {
            if ($ex = $APPLICATION->GetException())
            {
                $error = $ex->GetString();
            }
            return $error;
        }

    }

    /**
     * метод вызывается на кроне отправляет уведомления в ленту новостей сущностей - владельцев переполненных дисков
     *
     * @return array
     */
    public function sendNotifications() {
        $notificationsSended = [];
        $entitiesToBeInformed = $this->matchDisksWithBlogsUsers(); // все сущности, диски которых переполнены

        // для каждой сущности вызываем метод sendNotificationIntoNewsFeed()
        foreach( $entitiesToBeInformed as $singleEntity ) {
            $logId = $this->sendNotificationIntoNewsFeed( $singleEntity );

            if($logId > 0) {
                $notificationsSended[] = $logId;
            } else {
                $notificationsSended[] = 'Не удалось создать уведомление для сущности с ID= ' . $singleEntity['ENTITY_ID'] . ". Тип сущности= " . $singleEntity['ENTITY_TYPE'] . ".";
            }
        }

        return $notificationsSended;
    }

    /** -------------------------------------------------------------------------------------------------------------------------------- */

    /**
     * Метод возвращает иденитификаторы дисков уже уведомленных о превышении квоты
     *
     * @return array
     */
    private function getAllNotifiedDisks() {
        $disks = (self::$highloadEntityDataClass)::getList([
            'select' => ["*"]
        ])->fetchAll();

        return $disks;
    }

    /**
     * Метод возвращает массив всех дисков с их квотами, которые есть в highload блоке с уведомлениями
     *
     */
    private function getCapacityOfDisks() {
        $diskNotified = $this->getAllNotifiedDisks();
        $diskIds = array_column($diskNotified,'UF_DISK_ID');

        $disksWithQuota = Disk::getDiskQuotaCompare(['ID' => $diskIds]);

        return $disksWithQuota;
    }

    /**
     * метод вызывается на кроне в цикле проверяет превышена ли квота дисков, если это не так то запись об этом диске удаляется из highload блока
     *
     */
    public function deleteNotificationIfQuotaIsNotOversized() {
        $notificationsDeleted = [];
        $disksWithQuota = $this->getCapacityOfDisks();

        foreach( $disksWithQuota as $diskWithQuota) {
            if( intval( $disksWithQuota[3]['PERCENT'] )>0 && intval( $disksWithQuota[3]['PERCENT'] )<90 ) {
                (self::$highloadEntityDataClass)::delete($diskWithQuota['ID']);
                $notificationsDeleted[] = $disksWithQuota['ID'];
            }
        }

        return $notificationsDeleted;
    }
}
