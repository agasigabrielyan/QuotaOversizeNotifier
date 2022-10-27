<?php
declare(strict_types = 1);

namespace gpi\Helper;

use Bitrix\Disk\Internals\StorageTable;
use Bitrix\Disk\Internals\FolderTable;
use CSocNetUserToGroup;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

Loader::includeModule('disk');
Loader::includeModule('socialnetwork');

class Disk
{

    public static function getStorage(int $storageId = 0): \Bitrix\Disk\Storage
    {
        $storage = \Bitrix\Disk\Storage::loadById($storageId);
        if ($storage)
            return $storage;

        return null;
    }

    public static function addFolders(int $storageId = 0, array $folders = [])
    {
        if (count($folders) === 0)
            return false;

        if ($storageId === 0)
            return false;

        $storage = self::getStorage($storageId);

        $arParams = [
            "replace_space" => "-",
            "replace_other" => "-"
        ];

        foreach ($folders as $folderItem)
        {
            $folderItem['XML_ID'] = md5(\CUtil::translit($folderItem['XML_ID'], LANGUAGE_ID, $arParams));
            $storage->addFolder($folderItem);
        }

        return true;
    }

    public static function getFolders(int $storageId = 0): array
    {
        if ($storageId === 0)
            return false;

        $rsFolders = FolderTable::getList([
            'filter' => [
                'STORAGE_ID' => $storageId,
                'TYPE' => FolderTable::TYPE_FOLDER,
                '!XML_ID' => false
            ]
        ]);

        $result = [];
        while ($arFolder = $rsFolders->fetch())
        {
            $result[] = array_map(function($item) {
                if ($item instanceof \Bitrix\Main\Type\DateTime)
                    $item = $item->format('d.m.Y H:i:s');

                return $item;
            }, $arFolder);
        }

        return $result;
    }

    public static function getFiles(int $storageId = 0, array $folder = []): array
    {
        if ($storageId === 0)
            return false;

        $filter = [
            'STORAGE_ID' => $storageId,
            'TYPE' => FolderTable::TYPE_FILE,
            //'!XML_ID' => false
        ];

        if (count($folder) > 0)
        {
            $filter['PARENT_ID'] = $folder;
        }

        $rsFiles = FolderTable::getList([
            'filter' => $filter
        ]);

        $result = [];
        while ($arFile = $rsFiles->fetch()) {
            $result[] = array_map(function ($item) {
                if ($item instanceof \Bitrix\Main\Type\DateTime)
                    $item = $item->format('d.m.Y H:i:s');

                return $item;
            }, $arFile);
        }

        return $result;
    }

    public static function uploadFiles(int $storageId = 0, string $xmlId, array $files = []): bool
    {
        if ($storageId === 0)
            return false;

        $storage = self::getStorage($storageId);

        $folder = $storage->getChild([
            'XML_ID' => $xmlId,
            'TYPE' => FolderTable::TYPE_FOLDER
        ]);

        if ($folder) {
            foreach ($files as $fileItem)
            {
                $fileArray = \CFile::MakeFileArray($fileItem);
                $folder->uploadFile($fileArray, ['CREATED_BY' => CurrentUser::get()->getId()]);
            }
        }


        return true;
    }


    public static function setQuota(int $storageId = 0, float $sizeGb = 0): bool
    {
        if ($storageId === 0 || $sizeGb === 0)
            return false;

        $arStorage = self::getDiskSize(['ID' => $storageId]);
        if (count($arStorage) > 0)
            $arStorage = current($arStorage);

		if ($sizeGb == 0)
        	$sizeGb = (float)$arStorage['DISK_SIZE'] + (float)Option::get('gazprom.entitiesconstructor', 'DEFAULT_DISK_SIZE');
		else
			$sizeGb+=(float)$arStorage['DISK_SIZE'];

        $storage = \Bitrix\Disk\Storage::loadById($storageId);
        if ($storage) {
            return $storage->setSizeLimit($sizeGb * pow(10, 9));
        }

        return false;
    }

    public static function setQuotaSimple(int $storageId = 0, float $sizeGb = 0): bool
    {
        if ($storageId === 0 || $sizeGb === 0)
            return false;

        $storage = \Bitrix\Disk\Storage::loadById($storageId);
        if ($storage) {
            return $storage->setSizeLimit($sizeGb * pow(10, 9));
        }

        return false;
    }

    public static function getStorageList(array $filter = [], callable $callback = null)
    {
        $rsGroup = CSocNetUserToGroup::GetList([], [
            'USER_ID' => \Bitrix\Main\Engine\CurrentUser::get()->getId()
        ]);

        $groupId = [];
        while ($arGroup = $rsGroup->Fetch())
        {
            $groupId[] = $arGroup['GROUP_ID'];
        }

        if (count($groupId) === 0)
            return [];

        $filter['ENTITY_ID'] = $groupId;

        $rsStorageList = StorageTable::getList([
            'filter' => $filter,
            'order' => [
                'NAME' => 'ASC'
            ]
        ]);

        $result = [];
        while ($arStorage = $rsStorageList->fetch())
        {
            if (is_callable($callback))
            {
                $result[] = call_user_func($callback, $arStorage);
            } else
                $result[] = $arStorage;
        }

        return $result;
    }

    public static function getQuota(array $filter = []): array
    {
        return self::getStorageList($filter, function($item) {

            $item['ENTITY_MISC_DATA'] = unserialize($item['ENTITY_MISC_DATA']);

            return $item;
        });
    }

    public static function getDiskSize(array $filter = [])
    {
        $rsStorage = \Bitrix\Disk\Internals\StorageTable::getList([
            'select' => [
                'ID',
                'NAME',
                'DISK_SIZE' ,
                'DISK_NAME',
                'DISK_SIZE_TITLE',
                'GROUP_OWNER_ID' => 'group.OWNER_ID',
                'OWNER_NAME',
                'OWNER_DEPARTMENT' => 'owner.UF_DEPARTMENT',
                'ENTITY_TYPE',
                'ENTITY_ID',
                'USER_OWNER_NAME',
                'USER_OWNER_DEPARTMENT' => 'user_owner.UF_DEPARTMENT'
            ],
            'filter' => $filter,
            'runtime' => [
                'obj' => [
                    'data_type' => \Bitrix\Disk\Internals\ObjectTable::getEntity(),
                    'reference' => [
                        '=this.ID' => 'ref.STORAGE_ID'
                    ],
                    'join_type' => 'left'
                ],
                'group' => [
                    'data_type' => \Bitrix\Socialnetwork\WorkgroupTable::getEntity(),
                    'reference' => [
                        '=this.ENTITY_ID' => 'ref.ID'
                    ],
                    'join_type' => 'left'
                ],
                'owner' => [
                    'data_type' => \Bitrix\Main\UserTable::getEntity(),
                    'reference' => [
                        '=this.group.OWNER_ID' => 'ref.ID',
                    ],
                    'join_type' => 'left'
                ],
                'user_owner' => [
                    'data_type' => \Bitrix\Main\UserTable::getEntity(),
                    'reference' => [
                        '=this.ENTITY_ID' => 'ref.ID',
                    ],
                    'join_type' => 'left'
                ],
                'DISK_NAME' => [
                    'expression' => [
                        'concat(%s, " [", %s, "]")', 'NAME', 'ID'
                    ],
                    'data_type' => 'string'
                ],
                'DISK_SIZE_TITLE' => [
                    'expression' => [
                        'concat(%s, " [", %s, "] - ", coalesce(round(sum(%s)/pow(10,9) ,2), 0), "  ГБ")', 'NAME', 'ID', 'obj.SIZE'
                    ],
                    'data_type' => 'string'
                ],
                'DISK_SIZE' => [
                    'expression' => [
                        'coalesce(round(sum(%s)/pow(10,9) ,2), 0)', 'obj.SIZE'
                    ],
                    'data_type' => 'float'
                ],
                'OWNER_NAME' => [
                    'expression' => [
                        'concat(coalesce(%s), " ", coalesce(%s), " ", coalesce(%s))',
                        'owner.LAST_NAME',
                        'owner.NAME',
                        'owner.SECOND_NAME'
                    ],
                    'data_type' => 'string'
                ],
                'USER_OWNER_NAME' => [
                    'expression' => [
                        'concat(coalesce(%s), " ", coalesce(%s), " ", coalesce(%s))',
                        'user_owner.LAST_NAME',
                        'user_owner.NAME',
                        'user_owner.SECOND_NAME'
                    ],
                    'data_type' => 'string'
                ]
            ],
            'group' => [
                'ID', 'NAME'
            ]
        ]);

        $result = [];
        $depList = [];
        while ($arStorage = $rsStorage->fetch())
        {
            if ($arStorage['OWNER_DEPARTMENT'][0] > 0)
                $depList[] = $arStorage['OWNER_DEPARTMENT'][0];

            if ($arStorage['USER_OWNER_DEPARTMENT'][0] > 0)
                $depList[] = $arStorage['USER_OWNER_DEPARTMENT'][0];

            $result[] = $arStorage;
        }

        $depList = array_unique($depList);

        if (count($result) === 0)
            return [];

        if (count($depList) > 0)
        {
            $rsDepartment = \Bitrix\Iblock\SectionTable::getList([
                'select' => [
                    'ID',
                    'NAME',
                    'PARENT_ID' => 'IBLOCK_SECTION_ID',
                    'PARENT_NAME' => 'parent.NAME'
                ],
                'filter' => [
                    'IBLOCK_ID' => Option::get('intranet', 'iblock_structure'),
                    'ID' => $depList
                ],
                'runtime' => [
                    'parent' => [
                        'data_type' => \Bitrix\Iblock\SectionTable::getEntity(),
                        'reference' => [
                            '=this.IBLOCK_SECTION_ID' => 'ref.ID'
                        ],
                        'join_type' => 'left'
                    ]
                ]
            ]);

            $depList = [];
            while ($arDepartment = $rsDepartment->fetch())
            {
                $depList[$arDepartment['ID']] = $arDepartment;
            }
        }

        $result = array_map(function($item) use ($depList) {

            $prefix = 'OWNER_';
            if ($item['ENTITY_TYPE'] == 'Bitrix\Disk\ProxyType\User')
                $prefix = 'USER_OWNER_';

            $item['OWNER_DEPARTMENT'] = $item[$prefix.'DEPARTMENT'][0];
            if (!empty($depList[$item[$prefix . 'DEPARTMENT']]['NAME']))
                $item['OWNER_DEPARTMENT_NAME'] = $depList[$item['OWNER_DEPARTMENT']]['NAME'];
            $item['PARENT_DEPARTMENT_ID'] = $depList[$item['OWNER_DEPARTMENT']]['PARENT_ID'];
            $item['PARENT_DEPARTMENT_NAME'] = $depList[$item['OWNER_DEPARTMENT']]['PARENT_NAME'];

            unset($item['USER_OWNER_DEPARTMENT']);
            unset($item['ENTITY_TYPE']);

            return $item;
        }, $result);

        return $result;
    }

    public static function getFolderSize(array $filter = [])
    {
        $filter['!OBJECT_PARENT_ID'] = false;
        $filter['>DISK_SIZE'] = 0;
        $rsStorage = \Bitrix\Disk\Internals\StorageTable::getList([
            'select' => [
                'ID',
                'NAME',
                'OBJECT_PARENT_ID' => 'obj.PARENT_ID',
                'FOLDER_NAME' => 'folder.NAME',
                'DISK_SIZE'
            ],
            'filter' => $filter,
            'runtime' => [
                'obj' => [
                    'data_type' => \Bitrix\Disk\Internals\ObjectTable::getEntity(),
                    'reference' => [
                        '=this.ID' => 'ref.STORAGE_ID'
                    ],
                    'join_type' => 'left'
                ],
                'folder' => [
                    'data_type' => \Bitrix\Disk\Internals\ObjectTable::getEntity(),
                    'reference' => [
                        '=this.OBJECT_PARENT_ID' => 'ref.ID'
                    ],
                    'join_type' => 'left'
                ],
                'DISK_SIZE' => [
                    'expression' => [
                        'coalesce(round(sum(%s)/pow(10,9) ,2), 0)', 'obj.SIZE'
                    ],
                    'data_type' => 'float'
                ],

            ],
            'group' => [
                'ID', 'NAME', 'OBJECT_PARENT_ID'
            ],
            'order' => [
                'NAME', 'OBJECT_PARENT_ID'
            ]
        ]);

        $result = [];
        $depList = [];
        while ($arStorage = $rsStorage->fetch()) {

            $result[] = $arStorage;
        }

        return $result;
    }


    public static function getDiskQuotaCompare(array $filter = [])
    {
        $rsStorage = \Bitrix\Disk\Internals\StorageTable::getList([
            'select' => [
                'ID',
                'NAME',
                'DISK_SIZE',
                'DISK_SIZE_POW',
                'ENTITY_MISC_DATA'
            ],
            'filter' => $filter,
            'runtime' => [
                'obj' => [
                    'data_type' => \Bitrix\Disk\Internals\ObjectTable::getEntity(),
                    'reference' => [
                        '=this.ID' => 'ref.STORAGE_ID'
                    ],
                    'join_type' => 'left'
                ],
                'DISK_SIZE' => [
                    'expression' => [
                        'sum(%s)', 'obj.SIZE'
                    ],
                    'data_type' => 'float'
                ],
                'DISK_SIZE_POW' => [
                    'expression' => [
                        'coalesce(round(sum(%s)/pow(10,9) ,4), 0)', 'obj.SIZE'
                    ],
                    'data_type' => 'float'
                ],
            ],
            'group' => [
                'ID', 'NAME'
            ]
        ]);

        $result = [];
        while ($arStorage = $rsStorage->fetch()) {

            if (!empty($arStorage['ENTITY_MISC_DATA'])) {
                $arStorage['ENTITY_MISC_DATA'] = unserialize($arStorage['ENTITY_MISC_DATA']);
                $arStorage['SIZE_LIMIT'] = $arStorage['ENTITY_MISC_DATA']['SIZE_LIMIT'];
                $arStorage['SIZE_LIMIT_POW'] = round($arStorage['SIZE_LIMIT'] / pow(10,9), 4);
            }
            unset($arStorage['ENTITY_MISC_DATA']);

            $arStorage['PERCENT'] = round(($arStorage['DISK_SIZE']/$arStorage['SIZE_LIMIT'])*100, 2);

            if ($arStorage['PERCENT'] > 0)
                $result[] = $arStorage;
        }

        return $result;
    }


}
