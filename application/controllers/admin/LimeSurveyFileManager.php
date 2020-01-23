<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
/*
 * LimeSurvey
 * Copyright (C) 2013 The LimeSurvey Project Team / Carsten Schmitz
 * All rights reserved.
 * License: GNU/GPL License v2 or later, see LICENSE.php
 * LimeSurvey is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 */

/**
 * Filemanagement Controller
 *
 * This controller is used in the global file management as well as the survey specific
 *
 * @package        LimeSurvey
 * @subpackage    Backend
 */

/**
 * Class LimeSurveyFileManager
 */
class LimeSurveyFileManager extends Survey_Common_Action
{
    /**
     * In controller error storage to have a centralizes error message system
     *
     * @var Object
     */
    private $oError = null;

    /**
     * globally available directories
     * @TODO make this a configuration in global config
     *
     * @var array
     */
    private $globalDirectories = [
        'upload' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'survey' . DIRECTORY_SEPARATOR . 'generalfiles',
        'upload' . DIRECTORY_SEPARATOR . 'global',
    ];

    /**
     * @return mixed
     */
    public function getAllowedFileExtensions()
    {
        return Yii::app()->getConfig('allowedfileuploads');
    }

    /**
     * Basic index function to call the view
     *
     * @param null $surveyid
     * @return void Renders HTML-page
     * @throws CException
     * @throws CHttpException
     */
    public function index($surveyid = null)
    {
        $possibleFolders = $this->collectFolderList($surveyid);

        $aTranslate = [
            'File management' => gT('File management'),
            'Upload' => gT('Upload'),
            'Cancel move' => gT('Cancel move'),
            'Cancel copy' => gT('Cancel copy'),
            'Move' => gT('Move'),
            'Copy' => gT('Copy'),
            'Upload a file' => gT('Upload a file'),
            'File could not be uploaded' => gT('File could not be uploaded'),
            'Drag and drop here, or click once to start uploading' =>
                gT('Drag and drop here, or click once to start uploading'),
            'File is uploaded to currently selected folder' => gT('File is uploaded to currently selected folder'),
            'An error has happened and no files could be located' =>
                gT('An error has happened and no files could be located'),
            'An error has occured and the file list could not be loaded:' =>
                gT('An error has occured and the file list could not be loaded:'),
            'An error has occured and the folders could not be loaded:' =>
                gT('An error has occured and the folders could not be loaded:'),
            'An error has occured and the file(s) could not be uploaded:' =>
                gT('An error has occured and the folders could not be loaded:'),
            'An error has occured and the selected files ycould not be downloaded.' =>
                gT('An error has occured and the selected files ycould not be downloaded.'),
            'File name' => gT('File name'),
            'Type' => gT('Type'),
            'Size' => gT('Size'),
            'Mod time' => gT('Mod time'),
            'Action' => gT('Action'),
            'Delete file' => gT('Delete file'),
            'Copy file' => gT('Copy file'),
            'Move file' => gT('Move file'),
            'Allowed file formats' => gT('Allowed file formats'),
            'File formats' => '.'.gT(implode(", .", $this->allowedFileExtensions))
        ];

        Yii::app()->getClientScript()->registerPackage('filemanager');
        $aData['jsData'] = [
            'surveyid' => $surveyid,
            'possibleFolders' => $possibleFolders,
            'i10N' => $aTranslate,
            'allowedFileTypes' => $this->allowedFileExtensions,
            'baseUrl' => $this->getController()->createUrl('admin/filemanager', ['sa' => '']),
        ];
        $renderView = $surveyid == null ? 'view' : 'surveyview';

        if ($surveyid !== null) {
            $oSurvey = Survey::model()->findByPk($surveyid);
            $aData['surveyid'] = $surveyid;
            $aData['presetFolder'] = 'upload' . DIRECTORY_SEPARATOR . 'surveys' . DIRECTORY_SEPARATOR . $surveyid;
            $aData['surveybar']['buttons']['view'] = true;
            $aData['title_bar']['title'] = $oSurvey->currentLanguageSettings->surveyls_title
                . ' (' . gT('ID') . ':' . $surveyid . ')';
            $aData['subaction'] = gT("File manager");
        }

        $this->_renderWrappedTemplate('SurveyFiles', $renderView, $aData);
    }

    /**
     * @param null $surveyid
     * @throws CException
     */
    public function getFilesForSurvey($surveyid = null)
    {
        $folders = $this->collectCompleteFolderList($surveyid);
        $result = [];

        foreach ($folders as $folder) {
            $result[$folder] = $this->collectFileList($folder);
        }

        $this->printJsonResponse($result);
        return;
    }

    /**
     * Calls the list of files in the selected folder
     *
     * @param string $folder
     * @param int|null $iSurveyId
     * @return void Renders json document
     * @throws CException
     * @throws LSJsonException
     */
    public function getFileList($folder, $iSurveyId = null)
    {
        $directory = $this->checkFolder($folder, $iSurveyId);

        if ($directory === false) {
            $this->throwError();
            return;
        }

        $fileList = $this->collectFileList($directory);

        $this->printJsonResponse($fileList);
        return;
    }

    /**
     * @param null $iSurveyId
     * @throws CException
     */
    public function getFolderList($iSurveyId = null)
    {
        $aAllowedFolders = $this->collectRecursiveFolderList($iSurveyId);

        $this->printJsonResponse($aAllowedFolders);
        return;
    }

    /**
     * @throws CException
     * @throws LSJsonException
     */
    public function deleteFile()
    {
        $iSurveyId = Yii::app()->request->getPost('surveyid');
        $file = Yii::app()->request->getPost('file');
        $folder = dirname($file['path']);
        $checkDirectory = $this->checkFolder($folder, $iSurveyId);

        if ($checkDirectory === false) {
            $this->throwError();
            return;
        }

        $realFilePath = dirname(Yii::app()->basePath) . DIRECTORY_SEPARATOR . $file['path'];

        //Throw exception if file does not exist
        if (!$this->checkTargetExists($realFilePath)) {
            $this->setError(
                "FILE_NOT_EXISTING",
                gT("The file does not exist")
            );
            $this->throwError();
        }

        if (!unlink($realFilePath)) {
            $this->setError(
                "DELETE_FILE_ERROR",
                gT("The file could not be deleted")
            );
            $this->throwError();
        }
        $this->printJsonResponse(
            [
                'success' => true,
                'message' => sprintf(gT("File successfully deleted"), $file['shortName']),
            ]
        );
    }

    /**
     * @throws CException
     * @throws LSJsonException
     */
    public function transitFiles()
    {
        $folder = Yii::app()->request->getPost('targetFolder');
        $iSurveyId = Yii::app()->request->getPost('surveyid');
        $files = Yii::app()->request->getPost('files');
        $action = Yii::app()->request->getPost('action');

        // TODO: Remove unused variable: $checkDirectory
        $checkDirectory = $this->checkFolder($folder, $iSurveyId);

        foreach($files as $file) {
            $realTargetPath = dirname(Yii::app()->basePath) . DIRECTORY_SEPARATOR . $folder;
            $fileDestination = realpath($realTargetPath) . DIRECTORY_SEPARATOR . $file['shortName'];

            $realFilePath = dirname(Yii::app()->basePath) . DIRECTORY_SEPARATOR . $file['path'];
            $fileSource = realpath($realFilePath);

            if ($this->checkTargetExists($fileDestination) && Yii::app()->getConfig('overwritefiles') == 0) {
                $ext = pathinfo($fileDestination, PATHINFO_EXTENSION);
                $shorthash = hash('adler32', microtime());
                $fileDestination = preg_replace("/\." . $ext . "/", "-" . $shorthash . "." . $ext, $fileDestination);
            }

            if ($action == 'copy') {
                if (!copy($fileSource, $fileDestination)) {
                    $this->setError(
                        'COPY_FAILED',
                        gT("Your file could not be copied")
                    );
                    $this->throwError();
                    return;
                }
            } else if ($action == 'move') {
                if (!@rename($fileSource, $fileDestination)) {
                    $this->setError(
                        'MOVE_FAILED',
                        gT("Your file could not be moved")
                    );
                    return;
                }
            }
        }

        $successMessage = $action == 'copy' ? gT("Files successfully copied") : gT("Files successfully moved");
        $this->printJsonResponse([
            'success' => true,
            'message' => $successMessage,
        ]);
        return;

    }

    /**
     * Action to upload a file returns a json document
     * @TODO Currently a naive extension filter is in place this needs to be secured against executables.
     *
     * @return void
     * @throws CException
     * @throws LSJsonException
     */
    public function uploadFile()
    {
        $folder = Yii::app()->request->getPost('folder');
        $iSurveyId = Yii::app()->request->getPost('surveyid', null);

        if (($iSurveyId == 'null' || $iSurveyId == null) && !preg_match("/generalfiles/", $folder)) {
            $iSurveyId = null;
            $folder = 'upload' . DIRECTORY_SEPARATOR . 'global';
        }

        $directory = $this->checkFolder($folder, $iSurveyId);

        if ($directory === false) {
            $this->throwError();
            return;
        }

        $debug[] = $_FILES;

        if ($_FILES['file']['error'] == 1 || $_FILES['file']['error'] == 2) {
            $this->setError(
                'MAX_FILESIZE_REACHED',
                sprintf(gT("Sorry, this file is too large. Only files up to %01.2f MB are allowed."), getMaximumFileUploadSize() / 1024 / 1024)
            );
            $this->throwError();
            return;
        }

        $path = $_FILES['file']['name'];
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        // Naive fileextension test => needs proper evaluation

        if ($this->isExtensionAllowed($ext, 'upload') === false) {
            $this->setError(
                'FILETYPE_NOT_ALLOWED',
                gT("Sorry, this file type is not allowed. Please contact your administrator for a list of allowed filetypes.")
            );
            $this->throwError();
            return;
        }

        $destdir = dirname(Yii::app()->basePath) . DIRECTORY_SEPARATOR . $folder;
        $filename = sanitize_filename($_FILES['file']['name'], false, false, false); // Don't force lowercase or alphanumeric
        $fullfilepath = $destdir . DIRECTORY_SEPARATOR . $filename;
        $fullfilepath = preg_replace("%".DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR."%", DIRECTORY_SEPARATOR, $fullfilepath);

        if ($this->checkTargetExists($fullfilepath) && Yii::app()->getConfig('overwritefiles') == 0) {
            $ext = pathinfo($fullfilepath, PATHINFO_EXTENSION);
            $shorthash = hash('adler32', microtime());
            $fullfilepath = preg_replace("/\." . $ext . "/", "-" . $shorthash . ".", $fullfilepath);
        }

        $debug[] = $destdir;
        $debug[] = $filename;
        $debug[] = $fullfilepath;

        if (!is_writable($destdir)) {
            $this->setError(
                'FILE_DESTINATION_UNWRITABLE',
                sprintf(
                    gT('An error occurred uploading your file. The folder (%s) is not writable for the webserver.'),
                    $folder
                )
            );
            $this->throwError();
            return;
        }


        if ($ext == 'zip') {
            App()->loadLibrary('admin.pclzip');
            $zip = new PclZip($_FILES['file']['tmp_name']);
            $aExtractResult = $zip->extract(PCLZIP_OPT_PATH, $destdir, PCLZIP_CB_PRE_EXTRACT, 'resourceExtractFilter');

            if ($aExtractResult === 0) {
                $this->setError(
                    'FILE_NOT_A_VALID_ARCHIVE',
                    gT("This file is not a valid ZIP file archive. Import failed.")
                );
                $this->throwError();
                return;
            };

            $linkToImage = 'about:blank';
            $message = sprintf(gT("File %s uploaded and %s files unpacked"), $filename, safecount($aExtractResult));
        } else {
            if (
                !move_uploaded_file(
                    $_FILES['file']['tmp_name'],
                    $fullfilepath
                )
            ) {
                $this->setError(
                    'FILE_COULD NOT_BE_MOVED',
                    sprintf(
                        gT(
                            'An error occurred uploading your file.
                             This may be caused by incorrect permissions for the target folder. (%s)'),
                        $folder
                    )
                );
                $this->throwError();
                return;
            }
            $message = sprintf(gT("File %s uploaded"), $filename);
            $linkToImage = Yii::app()->baseUrl . '/' . $folder . '/' . $filename;
        }

        $this->printJsonResponse(
            [
                'success' => true,
                'message' => $message,
                'src' => $linkToImage,
                'debug' => $debug,
            ]
        );
    }

    /**
     * @return void
     * @throws CException
     */
    public function downloadFiles()
    {
        App()->loadLibrary('admin.pclzip');

        $folder = basename(Yii::app()->request->getPost('folder', 'global'));
        $files = Yii::app()->request->getPost('files');

        $tempdir = Yii::app()->getConfig('tempdir');
        $randomizedFileName = $folder.'_'.substr(md5(time()),3,13).'.zip';
        $zipfile = $tempdir.DIRECTORY_SEPARATOR.$randomizedFileName;
        $arrayOfFiles = array_map( function($file){ return $file['path']; }, $files);
        $archive = new PclZip($zipfile);
        // TODO: Remove unused variable: $checkFileCreate
        $checkFileCreate = $archive->create($arrayOfFiles, PCLZIP_OPT_REMOVE_ALL_PATH);
        $urlFormat = Yii::app()->getUrlManager()->getUrlFormat();
        $getFileLink = Yii::app()->createUrl('admin/filemanager/sa/getZipFile');
        if ($urlFormat == 'path') {
            $getFileLink .= '?path='.$zipfile;
        } else {
            $getFileLink .= '&path='.$zipfile;
        }

        $this->printJsonResponse(
            [
                'success' => true,
                'message' => sprintf(gT("Files ready for download in archive %s."), $randomizedFileName),
                'downloadLink' => $getFileLink ,
            ]
        );
    }

    /**
     * @param $path
     * @return void
     */
    public function getZipFile($path)
    {
        $filename = basename($path);

        if (is_file($path) || true) {
            // Send the file for download!
            header("Expires: 0");
            header("Cache-Control: must-revalidate");
            header("Content-Type: application/force-download");
            header("Content-Disposition: attachment; filename=$filename");
            header("Content-Description: File Transfer");

            @readfile($path);

            // Delete the temporary file
            unlink($path);
        }
    }

    /**
     * Naive test for file extension
     * @TODO enhance this for file uploads
     *
     * @param string $fileExtension
     * @param string $purpose
     * @return bool
     */
    private function isExtensionAllowed($fileExtension, $purpose = 'show')
    {
        if ($purpose == 'upload') {
            return in_array($fileExtension, $this->allowedFileExtensions) || $fileExtension == 'zip';
        }

        if ($purpose == 'show') {
            return in_array($fileExtension, $this->allowedFileExtensions);
        }
    }

    /**
     * @param $fileDestination
     * @return bool
     */
    private function checkTargetExists($fileDestination)
    {
        return is_file($fileDestination);
    }

    /**
     * @param $sFolderPath
     * @param null $iSurveyId
     * @return bool
     * @throws CException
     */
    private function checkFolder($sFolderPath, $iSurveyId = null)
    {
        $aAllowedFolders = $this->collectCompleteFolderList($iSurveyId);
        $inInAllowedFolders = false;

        foreach ($aAllowedFolders as $folderName => $folderPath) {
            $inInAllowedFolders = (preg_match('%/?' . preg_quote($folderPath) . '/?%', $sFolderPath)) || $inInAllowedFolders;
        }

        if (!$inInAllowedFolders) {
            $this->setError('NO_PERMISSION', gT("You don't have permission to this folder"), null, [
                "sFolderPath" => $sFolderPath,
                "aAllowedFolders" => $aAllowedFolders,
            ]);
            return false;
        }

        $realPath = dirname(Yii::app()->basePath) . DIRECTORY_SEPARATOR . $sFolderPath;
        if (!is_dir($realPath)) {
            mkdir($realPath);
        }

        return $sFolderPath;
    }

    /**
     * Creates a list of files in the selected folder
     *
     * @param int|null $iSurveyId
     * @return array list of files [filename => filepath]
     */
    private function collectFileList($folderPath)
    {
        $directoryArray = array();

        $realPath = dirname(Yii::app()->basePath) . DIRECTORY_SEPARATOR . $folderPath;
        if (empty($realPath) || !is_dir($realPath)) {
            return $directoryArray;
        }

        $files = scandir($realPath);

        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            $fileRelativePath = $folderPath . DIRECTORY_SEPARATOR . $file;
            $fileRealpath = dirname(Yii::app()->basePath) . DIRECTORY_SEPARATOR . $fileRelativePath;
            $fileIsDirectoy = @is_dir($fileRealpath);
            $isImage =  @exif_imagetype($fileRealpath) !== false;
            if ($fileIsDirectoy) {
                continue;
            } else {
                $fileExt = strtolower(pathinfo($fileRealpath, PATHINFO_EXTENSION));
                if (!$this->isExtensionAllowed($fileExt)) {
                    continue;
                }

                $iconClassArray = LsDefaultDataSets::fileTypeIcons();
                $size = filesize($fileRealpath);
                if (isset($iconClassArray[$fileExt])) {
                    $iconClass = $iconClassArray[$fileExt];
                } else {
                    $iconClass = $iconClassArray['blank'];
                }
            }

            $sSystemDateFormat = getDateFormatData(Yii::app()->session['dateformat']);
            $iFileTimeDate = filemtime($fileRealpath);

            $linkToImage = Yii::app()->getBaseUrl(true) . '/' . $folderPath . '/' . rawurlencode($file);
            $hash = hash_file('md5', $fileRealpath);

            $directoryArray[$file] = [
                'iconClass' => $iconClass,
                'isImage' => $isImage,
                'src' => $linkToImage,
                'hash' => $hash,
                'path' => $fileRelativePath,
                'size' => $size,
                'shortName' => $file,
                'mod_time' => date($sSystemDateFormat['phpdate'] . ' H:i', $iFileTimeDate),
            ];
        }
        return $directoryArray;

    }

    /**
     * Creates an array of possible folders
     *
     * @param int|null $iSurveyId
     * @return array List of visible folders
     * @throws CException
     */
    private function collectFolderList($iSurveyId = null)
    {
        $folders = $this->globalDirectories;

        if ($iSurveyId != null) {
            $folders[] = 'upload' . DIRECTORY_SEPARATOR . 'surveys' . DIRECTORY_SEPARATOR . $iSurveyId;
        } else {
            $aSurveyIds = Yii::app()->db->createCommand()->select('sid')->from('{{surveys}}')->queryColumn();
            foreach ($aSurveyIds as $itrtSsurveyId) {
                if (Permission::model()->hasGlobalPermission('superadmin', 'read')
                    || Permission::model()->hasGlobalPermission('surveys', 'update')
                    || Permission::model()->hasSurveyPermission($itrtSsurveyId, 'surveylocale', 'update')
                ) {
                    $folders[] = 'upload' . DIRECTORY_SEPARATOR . 'surveys' . DIRECTORY_SEPARATOR . $itrtSsurveyId;
                }

            }
        }

        return $folders;
    }

    /**
     * Creates an array of all possible folders including child folders for access permission checks.
     *
     * @param int|null $iSurveyId
     * @return array List of visible folders
     * @throws CException
     */
    private function collectCompleteFolderList($iSurveyId = null)
    {
        $folders = $this->globalDirectories;

        if ($iSurveyId != null) {
            $folders[] = 'upload' . DIRECTORY_SEPARATOR . 'surveys' . DIRECTORY_SEPARATOR . $iSurveyId;
        } else {
            $aSurveyIds = Yii::app()->db->createCommand()->select('sid')->from('{{surveys}}')->queryColumn();
            foreach ($aSurveyIds as $itrtSsurveyId) {
                if (Permission::model()->hasGlobalPermission('superadmin', 'read')
                    || Permission::model()->hasGlobalPermission('surveys', 'update')
                    || Permission::model()->hasSurveyPermission($itrtSsurveyId, 'surveylocale', 'update')
                ) {
                    $folders[] = 'upload' . DIRECTORY_SEPARATOR . 'surveys' . DIRECTORY_SEPARATOR . $itrtSsurveyId;
                }

            }
        }
        $filelist = [];
        foreach ($folders as $folder) {
            $this->recursiveScandir($folder, $folders, $filelist);
        }

        return $folders;
    }

    /**
     * Recurses down the folder provided and adds a complete list of folders and files to the parametered arrays
     * !!! Array provided are changed !!!
     *
     * @param string $folder
     * @param array !by reference! $folderlist
     * @param array !by reference! $filelist
     * @return void
     */
    private function recursiveScandir($folder, &$folderlist, &$filelist)
    {
        $realPath = dirname(Yii::app()->basePath) . DIRECTORY_SEPARATOR . $folder;
        if (!file_exists($realPath)) {
            return $folderlist;
        }

        $scandirCurrent = scandir($realPath);
        foreach ($scandirCurrent as $fileDescriptor) {
            if ($fileDescriptor == '.' || $fileDescriptor == '..') {
                continue;
            }

            $childRelativePath = $folder . DIRECTORY_SEPARATOR . $fileDescriptor;
            $childRealPath = realpath(Yii::getPathOfAlias('basePath') . $childRelativePath);
            $childIsDirectoy = is_dir($childRealPath);

            if ($childIsDirectoy) {
                $folderlist[] = $childRelativePath;
                $this->recursiveScandir($childRelativePath, $folderlist, $filelist);
            } else {
                $filelist[] = $childRelativePath;
            }
        }
    }

    /**
     * Creates an associative array of the possible folders for the treeview
     *
     * @param int|null $iSurveyId
     * @return array List of visible folders
     * @throws CException
     */
    private function collectRecursiveFolderList($iSurveyId = null)
    {
        $folders = $this->collectFolderList($iSurveyId);
        $folderList = [];
        foreach ($folders as $folder) {
            $folderList[] = $this->composeFolderArray($folder);
        }
        return $folderList;
    }

    /**
     * Get the correct tree array representation including child folders for provided folder
     *
     * @param string $folder
     * @param string $level
     * @return array
     */
    private function composeFolderArray($folder, $level='0')
    {
        $realPath = dirname(Yii::app()->basePath) . DIRECTORY_SEPARATOR . $folder;
        if (!file_exists($realPath)) {
            $this->recursiveMkdir($realPath, 0750, true);
        }
        $allFiles = scandir($realPath);

        $childFolders = [];
        foreach ($allFiles as $childFile) {
            if ($childFile == '.' || $childFile == '..') {
                continue;
            }

            $childRelativePath = $folder . DIRECTORY_SEPARATOR . $childFile;
            $childRealPath = realpath(Yii::getPathOfAlias('basePath') . $childRelativePath);
            $childIsDirectoy = is_dir($childRealPath);

            if (!$childIsDirectoy) {
                continue;
            }

            $childFolders[] = $this->composeFolderArray($childRelativePath, ($level+1));

        }

        $pathArray = explode("/", $folder);
        $shortName = end($pathArray);

        $folderArray = [
            'key' => $shortName.'_'.$level,
            'folder' => $folder,
            'realPath' => $realPath,
            'shortName' => $shortName,
            'children' => $childFolders,
        ];
        return $folderArray;
    }

    /**
     * @param $folder
     * @param int $rights
     */
    private function recursiveMkdir($folder, $rights = 0755)
    {
        $folders = explode(DIRECTORY_SEPARATOR, $folder);
        $curFolder = array_shift($folders).DIRECTORY_SEPARATOR;
        foreach ($folders as $folder) {
            $curFolder.= DIRECTORY_SEPARATOR.$folder;
            if (!is_dir($curFolder) && strlen($curFolder) > 0 && !preg_match("/^[A-Za-z]:$/", $curFolder)) {
                mkdir($curFolder, $rights);
            }
        }
    }

    /**
     * Sets the internal error object
     *
     * @param string $code
     * @param string $message
     * @param string|null $title
     * @param null $debug
     * @return void
     */
    private function setError($code, $message, $title = '', $debug = null)
    {
        $this->oError = new FileManagerError();
        $this->oError->code = $code;
        $this->oError->message = $message;
        $this->oError->title = $title;
        $this->oError->debug = $debug;
    }

    /**
     * Prints a json document with the data provided as parameter
     *
     * @param array $data The data that should be transferred
     * @return void Renders JSON document
     * @throws CException
     */
    private function printJsonResponse($data)
    {
        $this->getController()->renderPartial(
            '/admin/super/_renderJson',
            [
                'success' => true,
                'data' => $data,
            ]
        );
    }

    /**
     * Prints a json document with the intercontroller error message
     *
     * @return void Renders JSON document
     * @throws LSJsonException
     */
    private function throwError()
    {
        throw new LSJsonException(
            500,
            (Yii::app()->getConfig('debug') > 0 ? $this->oError->code.': ' : '')
            .$this->oError->message,
            0
        );
    }
}

/**
 * Class FileManagerError
 */
class FileManagerError
{
    public $message;
    public $title;
    public $code;
}
