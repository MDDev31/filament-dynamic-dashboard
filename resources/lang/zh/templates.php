<?php

return [
    'flat_12' => [
        'name' => '标准',
        'description' => '12 列，行高 80 像素。适合大多数仪表板的默认布局。',
    ],
    'two_columns' => [
        'name' => '双栏',
        'description' => '两个相等的并排区域。',
        'left' => '左侧',
        'right' => '右侧',
    ],
    'three_columns' => [
        'name' => '三栏',
        'description' => '三个相等的并排区域。',
        'left' => '左侧',
        'middle' => '中间',
        'right' => '右侧',
    ],
    'four_cells_two_rows' => [
        'name' => '四宫格',
        'description' => '四个相等区域，分两行排列。',
        'top_left' => '左上',
        'top_right' => '右上',
        'bottom_left' => '左下',
        'bottom_right' => '右下',
    ],
    'sidebar_main' => [
        'name' => '侧栏',
        'description' => '窄侧栏加宽的主区域。',
        'sidebar' => '侧栏',
        'main' => '主区域',
    ],
    'header_2cols_footer' => [
        'name' => '报告',
        'description' => '全宽页眉、两个相等中间列、全宽页脚。',
        'header' => '页眉',
        'left' => '左侧',
        'right' => '右侧',
        'footer' => '页脚',
    ],
    'two_left_one_right' => [
        'name' => '展示',
        'description' => '左侧两个半宽堆叠区域，右侧一个全高区域。',
        'top_left' => '左上',
        'right' => '右侧（高）',
        'bottom_left' => '左下',
    ],
    'kpi_strip_chart' => [
        'name' => 'KPI',
        'description' => '顶部为简短的 KPI 条，下方为适合图表的高区域。',
        'kpi' => 'KPI',
        'chart' => '图表',
    ],
];
