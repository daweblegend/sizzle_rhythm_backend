<?php

// Ensure the upload directory exists
$UPLOAD_DIR = __DIR__ . '/../uploads';
if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0755, true);
}

// Configure upload for file uploads
function configureUpload(
    string $fieldName, // Field name for the file upload
    int $fileSizeLimit = 5 * 1024 * 1024, // Default file size limit: 5MB
    array $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'] // Default allowed file types
) {
    global $UPLOAD_DIR;
    
    return function() use ($fieldName, $fileSizeLimit, $allowedTypes, $UPLOAD_DIR) {
        // Check if file was uploaded
        if (!isset($_FILES[$fieldName])) {
            throw new Exception("No file uploaded for field: $fieldName");
        }
        
        $file = $_FILES[$fieldName];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload error: " . $file['error']);
        }
        
        // Check file size
        if ($file['size'] > $fileSizeLimit) {
            throw new Exception("File size exceeds limit of " . ($fileSizeLimit / 1024 / 1024) . "MB");
        }
        
        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception("Only " . implode(', ', $allowedTypes) . " files are allowed");
        }
        
        // Generate unique filename
        $uniqueSuffix = time() . '-' . mt_rand(100000000, 999999999);
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $fieldName . '-' . $uniqueSuffix . '.' . $ext;
        
        // Move uploaded file to destination
        $destination = $UPLOAD_DIR . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception("Failed to move uploaded file");
        }
        
        return [
            'fieldname' => $fieldName,
            'originalname' => $file['name'],
            'filename' => $filename,
            'path' => $destination,
            'size' => $file['size'],
            'mimetype' => $mimeType
        ];
    };
}

?>
