<?php

return [
    'flat_12' => [
        'name' => 'スタンダード',
        'description' => '12 列、行高 80px。ほとんどのダッシュボードに適したデフォルト。',
    ],
    'two_columns' => [
        'name' => 'デュオ',
        'description' => '同じ大きさの 2 つのセクションを横並びに配置。',
        'left' => '左',
        'right' => '右',
    ],
    'three_columns' => [
        'name' => 'トリオ',
        'description' => '同じ大きさの 3 つのセクションを横並びに配置。',
        'left' => '左',
        'middle' => '中央',
        'right' => '右',
    ],
    'four_cells_two_rows' => [
        'name' => 'クアッド',
        'description' => '同じ大きさの 4 つのセクションを 2 行に配置。',
        'top_left' => '左上',
        'top_right' => '右上',
        'bottom_left' => '左下',
        'bottom_right' => '右下',
    ],
    'sidebar_main' => [
        'name' => 'サイドバー',
        'description' => '狭いサイドバーと広いメインエリア。',
        'sidebar' => 'サイドバー',
        'main' => 'メイン',
    ],
    'header_2cols_footer' => [
        'name' => 'レポート',
        'description' => '全幅のヘッダー、等幅の 2 列、全幅のフッター。',
        'header' => 'ヘッダー',
        'left' => '左',
        'right' => '右',
        'footer' => 'フッター',
    ],
    'two_left_one_right' => [
        'name' => 'ショーケース',
        'description' => '左側に半幅のセクションを 2 つ縦に積み、右側に全高のセクションを 1 つ配置。',
        'top_left' => '左上',
        'right' => '右（縦長）',
        'bottom_left' => '左下',
    ],
    'kpi_strip_chart' => [
        'name' => 'KPI',
        'description' => '上部に短い KPI 帯、下部にチャートに適した高いエリア。',
        'kpi' => 'KPI',
        'chart' => 'チャート',
    ],
];
