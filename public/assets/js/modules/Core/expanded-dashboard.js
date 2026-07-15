(function () {
    'use strict';

    var charts = [];

    var parseOptions = function (element) {
        try {
            return JSON.parse(element.getAttribute('data-chart-options') || '{}');
        } catch (error) {
            return {};
        }
    };

    var initCharts = function () {
        if (!window.echarts) {
            return;
        }

        document.querySelectorAll('[data-dashboard-chart]').forEach(function (element) {
            var chart = window.echarts.init(element);
            chart.setOption(parseOptions(element));
            charts.push(chart);
        });
    };

    var resizeCharts = function () {
        charts.forEach(function (chart) {
            chart.resize();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCharts);
    } else {
        initCharts();
    }

    window.addEventListener('resize', resizeCharts);
})();
