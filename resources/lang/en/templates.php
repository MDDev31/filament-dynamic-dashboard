<?php

return [
    'flat_12' => [
        'name' => 'Standard',
        'description' => '12 columns, 80px row. Sensible default for most dashboards.',
    ],
    'two_columns' => [
        'name' => 'Split',
        'description' => 'Two equal sections side by side.',
        'left' => 'Left',
        'right' => 'Right',
    ],
    'three_columns' => [
        'name' => 'Trio',
        'description' => 'Three equal sections side by side.',
        'left' => 'Left',
        'middle' => 'Middle',
        'right' => 'Right',
    ],
    'four_cells_two_rows' => [
        'name' => 'Quad',
        'description' => 'Four equal sections; wraps to two rows.',
        'top_left' => 'Top left',
        'top_right' => 'Top right',
        'bottom_left' => 'Bottom left',
        'bottom_right' => 'Bottom right',
    ],
    'sidebar_main' => [
        'name' => 'Sidebar',
        'description' => 'Narrow sidebar and a wide main area.',
        'sidebar' => 'Sidebar',
        'main' => 'Main',
    ],
    'header_2cols_footer' => [
        'name' => 'Report',
        'description' => 'Full-width header, two equal middle columns, full-width footer.',
        'header' => 'Header',
        'left' => 'Left',
        'right' => 'Right',
        'footer' => 'Footer',
    ],
    'two_left_one_right' => [
        'name' => 'Showcase',
        'description' => 'Two stacked half-width sections on the left and one full-height section on the right.',
        'top_left' => 'Top left',
        'right' => 'Right (tall)',
        'bottom_left' => 'Bottom left',
    ],
    'kpi_strip_chart' => [
        'name' => 'KPI',
        'description' => 'A short KPI strip on top and a tall chart-friendly section below.',
        'kpi' => 'KPIs',
        'chart' => 'Charts',
    ],
];
