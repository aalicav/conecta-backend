<?php
return [
    'enable_remote' => true,
    'enable_javascript' => true,
    'enable_php' => false,
    'font_height_ratio' => 1.1,
    'is_remote_enabled' => true,
    'enable_css_float' => true,
    'enable_html5_parser' => true,
    'default_paper_size' => 'a4',
    'default_font' => 'sans-serif',
    'dpi' => 96,
    'enable_fontsubsetting' => true,
    'pdf_backend' => 'CPDF',
    'default_media_type' => 'screen',
    'default_paper_orientation' => 'portrait',
    'temp_dir' => sys_get_temp_dir(),
    'chroot' => realpath(base_path()),
    'allowed_protocols' => [
        'http' => ['timeout' => 5],
        'https' => ['timeout' => 5],
        'file' => ['timeout' => 5],
    ],
]; 