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

    var formatNumber = function (value) {
        if (window.AppNumbers && typeof window.AppNumbers.format === 'function') {
            return window.AppNumbers.format(value);
        }

        return String(value === null || value === undefined ? '' : value);
    };

    var applyNumericFormatters = function (options) {
        (options.series || []).forEach(function (series) {
            if (series.type !== 'pie') {
                return;
            }

            series.label = series.label || {};
            series.label.formatter = function (params) {
                return String(params.name || '') + ': ' + formatNumber(params.value);
            };
        });

        if (options.tooltip && options.tooltip.trigger === 'item') {
            options.tooltip.valueFormatter = formatNumber;
        }

        return options;
    };

    var initCharts = function () {
        if (!window.echarts) {
            return;
        }

        document.querySelectorAll('[data-dashboard-chart]').forEach(function (element) {
            var chart = window.echarts.init(element);
            chart.setOption(applyNumericFormatters(parseOptions(element)));
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
