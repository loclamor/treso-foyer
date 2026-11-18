// Enregistrement tree-shaké d'ECharts 6 : uniquement les charts et composants utilisés par l'app.
import { use } from 'echarts/core';
import { BarChart, LineChart, PieChart, SankeyChart, HeatmapChart } from 'echarts/charts';
import {
    TooltipComponent,
    LegendComponent,
    GridComponent,
    DataZoomComponent,
    VisualMapComponent,
    CalendarComponent,
    MarkAreaComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

use([
    BarChart,
    LineChart,
    PieChart,
    SankeyChart,
    HeatmapChart,
    TooltipComponent,
    LegendComponent,
    GridComponent,
    DataZoomComponent,
    VisualMapComponent,
    CalendarComponent,
    // Bandes des périodes labellisées (graphe quotidien, page Statistiques) : sans ce
    // composant, ECharts ignore silencieusement `markArea`.
    MarkAreaComponent,
    CanvasRenderer,
]);
