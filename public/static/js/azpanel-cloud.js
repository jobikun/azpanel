(function () {
    'use strict';

    function text(value) {
        return (value || '').replace(/\s+/g, ' ').trim();
    }

    function stateClass(value) {
        var state = value.toLowerCase();
        if (/running|enabled|active|online|ready|succeeded|正常|运行中|已启用/.test(state)) {
            return 'az-state-running';
        }
        if (/pending|starting|stopping|updating|warned|busy|处理中|启动中|停止中|警告/.test(state)) {
            return 'az-state-warning';
        }
        if (/stopped|deallocated|disabled|terminated|offline|failed|已停止|已禁用|失败/.test(state)) {
            return 'az-state-off';
        }
        return 'az-state-neutral';
    }

    function setText(node, value) {
        var next = String(value);
        if (node && node.textContent !== next) {
            node.textContent = next;
        }
    }

    function enhanceTable(table) {
        if (!table.closest('.az-cloud-page')) {
            return;
        }

        table.classList.add('az-responsive-table');
        var headers = Array.prototype.map.call(table.querySelectorAll('thead th'), function (header) {
            return text(header.textContent) || '选择';
        });

        var healthy = 0;
        var attention = 0;
        var dataRows = table.querySelectorAll('tbody tr:not(.az-empty-row)');
        Array.prototype.forEach.call(dataRows, function (row) {
            var cells = Array.prototype.filter.call(row.children, function (cell) {
                return cell.tagName === 'TD';
            });
            Array.prototype.forEach.call(cells, function (cell, index) {
                var label = headers[index] || '';
                if (label && !cell.getAttribute('data-label')) {
                    cell.setAttribute('data-label', label);
                }
                if (label.indexOf('状态') !== -1) {
                    cell.classList.remove('az-state-running', 'az-state-warning', 'az-state-off', 'az-state-neutral');
                    var statusClass = stateClass(text(cell.textContent));
                    cell.classList.add(statusClass);
                    if (statusClass === 'az-state-running') {
                        healthy++;
                    } else if (statusClass === 'az-state-warning' || statusClass === 'az-state-off') {
                        attention++;
                    }
                }
            });
        });

        var card = table.closest('.az-data-card');
        var count = card ? card.querySelector('[data-cloud-count]') : null;
        setText(count, dataRows.length);
        var page = table.closest('.az-cloud-page');
        if (page) {
            var rows = page.querySelector('[data-stat="rows"]');
            var healthyNode = page.querySelector('[data-stat="healthy"]');
            var attentionNode = page.querySelector('[data-stat="attention"]');
            setText(rows, dataRows.length);
            setText(healthyNode, healthy);
            setText(attentionNode, attention);
        }
    }

    function enhance(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('.az-cloud-page table'), enhanceTable);
    }

    function boot() {
        var currentPath = window.location.pathname.replace(/\/$/, '') || '/';
        Array.prototype.forEach.call(document.querySelectorAll('.az-drawer a[href]'), function (link) {
            var path = (link.getAttribute('href') || '').replace(/\/$/, '') || '/';
            if (path === currentPath) {
                link.classList.add('az-nav-active');
                var parent = link.closest('.mdui-collapse-item');
                if (parent) parent.classList.add('az-nav-parent-active');
            }
        });
        enhance(document);
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.target.closest && mutation.target.closest('.az-cloud-page')) {
                    var table = mutation.target.closest('table');
                    if (table) {
                        enhanceTable(table);
                    } else {
                        enhance(mutation.target);
                    }
                }
            });
        });
        Array.prototype.forEach.call(document.querySelectorAll('.az-cloud-page'), function (page) {
            observer.observe(page, { childList: true, subtree: true });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
