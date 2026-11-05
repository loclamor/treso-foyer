// Enregistrement tree-shaké d'ECharts 6 : uniquement les charts et composants utilisés par l'app.
import { use } from 'echarts/core';
import { BarChart, LineChart, SankeyChart, HeatmapChart } from 'echarts/charts';
import {
    TooltipComponent,
    LegendComponent,
    GridComponent,
    DataZoomComponent,
    VisualMapComponent,
    CalendarComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

use([
    BarChart,
    LineChart,
    SankeyChart,
    HeatmapChart,
    TooltipComponent,
    LegendComponent,
    GridComponent,
    DataZoomComponent,
    VisualMapComponent,
    CalendarComponent,
    CanvasRenderer,
]);
