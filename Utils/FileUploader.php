<?php

class FileUploader {
    /**
     * Uploads a file or multiple files to the specified directory
     *
     * @param array $fileData The file data from $_FILES
     * @param string $uploadDir The directory to upload to
     * @param string $prefix Optional prefix for the filename
     * @param array $allowedMimeTypes Optional array of allowed MIME types
     * @param int $maxFileSize Optional maximum file size in bytes
     * @return array Array with upload status, file paths, and any error messages
     */
    public static function upload($fileData, $uploadDir, $prefix = '', $allowedMimeTypes = null, $maxFileSize = null) {
        $result = [
            'success' => false,
            'files' => [],
            'errors' => []
        ];
        
        // Make sure the upload directory exists and is writable
        if (!is_dir($uploadDir)) {
            error_log("Upload directory doesn't exist: $uploadDir - Attempting to create it");
            if (!mkdir($uploadDir, 0755, true)) {
                $result['errors'][] = "Failed to create upload directory: $uploadDir";
                return $result;
            }
            error_log("Upload directory created: $uploadDir");
        }
        
        if (!is_writable($uploadDir)) {
            $result['errors'][] = "Upload directory is not writable: $uploadDir";
            return $result;
        }
        
        // Make sure the upload directory ends with a slash
        if (substr($uploadDir, -1) !== '/') {
            $uploadDir .= '/';
        }
        
        // No file uploaded
        if (!isset($fileData) || empty($fileData) || !is_array($fileData)) {
            $result['errors'][] = "No file data provided";
            return $result;
        }
        
        // Handle file input that has no files selected
        if (isset($fileData['error']) && $fileData['error'] === UPLOAD_ERR_NO_FILE) {
            $result['errors'][] = "No file selected for upload";
            return $result;
        }
        
        // Handle single file upload
        if (isset($fileData['name']) && !is_array($fileData['name'])) {
            return self::uploadSingleFile($fileData, $uploadDir, $prefix, $allowedMimeTypes, $maxFileSize);
        }
        
        // Handle multiple file upload
        $success = false;
        $fileResults = []; // Collect detailed results for each file
        
        if (!isset($fileData['name']) || !is_array($fileData['name'])) {
            $result['errors'][] = "Invalid file data structure for multiple file upload";
            return $result;
        }
        
        foreach ($fileData['name'] as $key => $name) {
            $fileResult = [
                'name' => $name,
                'index' => $key,
                'error_code' => $fileData['error'][$key] ?? 'not set',
                'size' => $fileData['size'][$key] ?? 'not set',
                'tmp_name' => $fileData['tmp_name'][$key] ?? 'not set',
                'tmp_exists' => isset($fileData['tmp_name'][$key]) && file_exists($fileData['tmp_name'][$key])
            ];
            
            // Skip files with upload errors
            if (isset($fileData['error'][$key]) && $fileData['error'][$key] !== 0) {
                $errorMessage = self::getUploadErrorMessage($fileData['error'][$key]);
                $result['errors'][] = "File $name: $errorMessage";
                $fileResult['status'] = 'error';
                $fileResult['message'] = $errorMessage;
                $fileResults[] = $fileResult;
                continue;
            }
            
            // Skip if no tmp_name or it doesn't exist
            if (!isset($fileData['tmp_name'][$key]) || !file_exists($fileData['tmp_name'][$key])) {
                $result['errors'][] = "File $name: Temporary file not found";
                $fileResult['status'] = 'error';
                $fileResult['message'] = "Temporary file not found";
                $fileResults[] = $fileResult;
                continue;
            }
            
            // Create single file data structure
            $singleFile = [
                'name' => $fileData['name'][$key],
                'type' => $fileData['type'][$key] ?? '',
                'tmp_name' => $fileData['tmp_name'][$key],
                'error' => $fileData['error'][$key] ?? UPLOAD_ERR_OK,
                'size' => $fileData['size'][$key] ?? 0
            ];
            
            // Upload the single file
            $uploadResult = self::uploadSingleFile($singleFile, $uploadDir, $prefix, $allowedMimeTypes, $maxFileSize);
            
            if ($uploadResult['success']) {
                $success = true;
                $result['files'] = array_merge($result['files'], $uploadResult['files']);
                $fileResult['status'] = 'success';
                $fileResult['uploaded_path'] = $uploadResult['files'];
            } else {
                $fileResult['status'] = 'error';
                $fileResult['upload_errors'] = $uploadResult['errors'];
            }
            
            $fileResults[] = $fileResult;
            $result['errors'] = array_merge($result['errors'], $uploadResult['errors']);
        }
        
        $result['success'] = $success;
        
        error_log("Upload results: " . json_encode([
            'success' => $success,
            'files_count' => count($result['files']),
            'errors_count' => count($result['errors']),
            'upload_dir' => $uploadDir
        ]));
        
        return $result;
    }
    
    /**
     * Upload a single file
     */
    private static function uploadSingleFile($fileData, $uploadDir, $prefix, $allowedMimeTypes, $maxFileSize) {
        $result = [
            'success' => false,
            'files' => [],
            'errors' => []
        ];
        
        // Check if name is empty
        if (empty($fileData['name']) || (is_array($fileData['name']) && empty($fileData['name'][0]))) {
            $result['errors'][] = "Empty file name";
            return $result;
        }
        
        // Check for upload errors
        if ($fileData['error'] !== 0) {
            $errorMessage = self::getUploadErrorMessage($fileData['error']);
            $result['errors'][] = "File {$fileData['name']}: $errorMessage";
            return $result;
        }
        
        // Check if tmp_name exists and is readable
        if (!isset($fileData['tmp_name']) || !file_exists($fileData['tmp_name']) || !is_readable($fileData['tmp_name'])) {
            $result['errors'][] = "Temporary file missing or not readable: " . ($fileData['tmp_name'] ?? 'not set');
            return $result;
        }
        
        // Check file size if max size is specified
        if ($maxFileSize !== null && $fileData['size'] > $maxFileSize) {
            $result['errors'][] = "File {$fileData['name']} exceeds the maximum file size";
            return $result;
        }
        
        // Check MIME type if allowed types are specified
        if ($allowedMimeTypes !== null) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($fileData['tmp_name']);
            
            if (!in_array($mimeType, $allowedMimeTypes)) {
                $result['errors'][] = "File {$fileData['name']} has an invalid MIME type: $mimeType";
                return $result;
            }
        }
        
        // Check upload directory
        if (!is_dir($uploadDir)) {
            // Try to create directory
            if (!mkdir($uploadDir, 0755, true)) {
                $result['errors'][] = "Upload directory does not exist and could not be created: $uploadDir";
                return $result;
            }
        }
        
        if (!is_writable($uploadDir)) {
            $result['errors'][] = "Upload directory is not writable: $uploadDir";
            return $result;
        }
        
        // Generate a unique filename
        $uniqueName = $prefix . time() . '-' . mt_rand() . '-' . basename($fileData['name']);
        $targetPath = $uploadDir . $uniqueName;
        
        // Move the uploaded file
        if (move_uploaded_file($fileData['tmp_name'], $targetPath)) {
            $result['success'] = true;
            $result['files'][] = str_replace(APP_ROOT . '/', '', $targetPath); // Store the relative path
        } else {
            $lastError = error_get_last();
            $result['errors'][] = "Failed to move uploaded file {$fileData['name']} to $targetPath";
            
            error_log("Upload error: " . json_encode([
                'error' => $lastError,
                'source' => $fileData['tmp_name'],
                'target' => $targetPath
            ]));
        }
        
        return $result;
    }
    
    /**
     * Get a human-readable error message for upload error codes
     */
    private static function getUploadErrorMessage($errorCode) {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return "The uploaded file exceeds the upload_max_filesize directive in php.ini";
            case UPLOAD_ERR_FORM_SIZE:
                return "The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form";
            case UPLOAD_ERR_PARTIAL:
                return "The uploaded file was only partially uploaded";
            case UPLOAD_ERR_NO_FILE:
                return "No file was uploaded";
            case UPLOAD_ERR_NO_TMP_DIR:
                return "Missing a temporary folder";
            case UPLOAD_ERR_CANT_WRITE:
                return "Failed to write file to disk";
            case UPLOAD_ERR_EXTENSION:
                return "File upload stopped by extension";
            default:
                return "Unknown upload error";
        }
    }
}
