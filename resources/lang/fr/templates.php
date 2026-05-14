<?php

return [
    'flat_12' => [
        'name' => 'Standard',
        'description' => '12 colonnes, hauteur de ligne 80px. Mise en page par défaut adaptée à la plupart des tableaux de bord.',
    ],
    'two_columns' => [
        'name' => 'Duo',
        'description' => 'Deux sections égales côte à côte (6 + 6 sur 12).',
        'left' => 'Gauche',
        'right' => 'Droite',
    ],
    'three_columns' => [
        'name' => 'Trio',
        'description' => 'Trois sections égales côte à côte (4 + 4 + 4 sur 12).',
        'left' => 'Gauche',
        'middle' => 'Milieu',
        'right' => 'Droite',
    ],
    'four_cells_two_rows' => [
        'name' => 'Quad',
        'description' => 'Quatre sections égales de 6 colonnes ; passe à la ligne suivante.',
        'top_left' => 'Haut gauche',
        'top_right' => 'Haut droite',
        'bottom_left' => 'Bas gauche',
        'bottom_right' => 'Bas droite',
    ],
    'sidebar_main' => [
        'name' => 'Latéral',
        'description' => 'Barre latérale étroite (4 sur 12) et large zone principale (8 sur 12).',
        'sidebar' => 'Barre latérale',
        'main' => 'Principal',
    ],
    'header_2cols_footer' => [
        'name' => 'Rapport',
        'description' => 'En-tête pleine largeur, deux colonnes centrales égales, pied de page pleine largeur.',
        'header' => 'En-tête',
        'left' => 'Gauche',
        'right' => 'Droite',
        'footer' => 'Pied de page',
    ],
    'two_left_one_right' => [
        'name' => 'Vitrine',
        'description' => 'Deux sections empilées à gauche et une section haute à droite.',
        'top_left' => 'Haut gauche',
        'right' => 'Droite (haute)',
        'bottom_left' => 'Bas gauche',
    ],
    'kpi_strip_chart' => [
        'name' => 'KPI',
        'description' => 'Un court bandeau de KPI en haut et une grande section dédiée aux graphiques en dessous.',
        'kpi' => 'KPIs',
        'chart' => 'Graphiques',
    ],
];
