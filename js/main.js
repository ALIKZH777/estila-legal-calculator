document.addEventListener('DOMContentLoaded', () => {
    const EstilaCalculator = {
        // --- UTILITIES ---
        utils: {
            getFloat: (id) => parseFloat(document.getElementById(id).value.replace(/,/g, '')) || 0,
            getInt: (id) => parseInt(document.getElementById(id).value, 10) || 0,
            getVal: (id) => document.getElementById(id).value,
            isChecked: (id) => document.getElementById(id).checked,
            formatCurrency: (input) => {
                let value = input.value.replace(/,/g, '');
                if (!isNaN(value) && value.length > 0) {
                    const cursorPosition = input.selectionStart;
                    const originalLength = input.value.length;
                    input.value = parseFloat(value).toLocaleString('en-US');
                    const newLength = input.value.length;
                    input.selectionStart = input.selectionEnd = cursorPosition + (newLength - originalLength);
                } else {
                    input.value = '';
                }
            },
            formatAmount: (num) => num.toLocaleString('fa-IR'),
            createFraction: (n, d) => `<div class="fraction"><span class="numerator">${n}</span><span class="denominator">${d}</span></div>`,
            showError: (el, message) => {
                el.innerHTML = `<span class="font-bold">خطا:</span> ${message}`;
                el.classList.remove('hidden');
            },
            hideError: (el) => {
                el.classList.add('hidden');
                el.innerHTML = '';
            },
            showResult: (el, content) => {
                el.innerHTML = content;
                el.classList.remove('hidden');
                // Use a short timeout to allow the element to be visible before adding the animation class
                setTimeout(() => {
                    el.classList.add('visible');
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 50);
            },
            hideResult: (el) => {
                el.classList.remove('visible');
                setTimeout(() => {
                    el.classList.add('hidden');
                    el.innerHTML = '';
                }, 500); // Match animation duration
            }
        },

        // --- TAB MANAGEMENT ---
        initTabs: function() {
            const tabLinks = document.querySelectorAll(".tab-link");
            tabLinks.forEach(link => {
                link.addEventListener('click', (e) => {
                    const tabName = link.dataset.tab;
                    this.openTab(e.currentTarget, tabName);
                });
            });
            // Set default tab
            this.openTab(tabLinks[0], 'home');
        },

        openTab: function(clickedLink, tabName) {
            document.querySelectorAll(".tab-content").forEach(tc => tc.classList.add("hidden"));
            document.querySelectorAll(".tab-link").forEach(tl => tl.classList.remove("active"));

            const content = document.getElementById(tabName);
            if (content) {
                content.classList.remove("hidden");
            }
            clickedLink.classList.add("active");
        },

        // --- INITIALIZATION ---
        init: function() {
            this.initTabs();
            this.inheritance.init(this.utils);
            this.mahr.init(this.utils, window.cbiIndex || {});
            this.ujrat.init(this.utils, window.minWages || {});
            this.costs.init(this.utils);
            this.nafaqeh.init(this.utils);

            // Add global formatters
            document.querySelectorAll('input[type="text"], input[type="number"]').forEach(input => {
                if(input.id.includes('count') || input.id.includes('year')) return; // Don't format counts/years
                input.addEventListener('keyup', () => this.utils.formatCurrency(input));
            });
        },

        // --- INHERITANCE MODULE ---
        inheritance: {
            init: function(utils) {
                this.utils = utils;
                this.errorDiv = document.getElementById('error-message-inheritance');
                this.resultDiv = document.getElementById('result-section-inheritance');

                document.getElementById('calculate-btn-inheritance').addEventListener('click', () => this.calculate());
                document.getElementById('reset-btn-inheritance').addEventListener('click', () => this.reset());
            },
            reset: function() {
                document.getElementById('form-section-inheritance').reset();
                ['total-assets', 'will-amount', 'mahr-deduction', 'nafaqeh-deduction', 'burial-costs', 'other-debts'].forEach(id => document.getElementById(id).value = '');
                this.utils.hideResult(this.resultDiv);
                this.utils.hideError(this.errorDiv);
            },
            calculate: function() {
                this.utils.hideError(this.errorDiv);
                this.utils.hideResult(this.resultDiv);

                const totalAssets = this.utils.getFloat('total-assets');
                if (totalAssets <= 0) {
                    this.utils.showError(this.errorDiv, 'لطفاً مبلغ کل ماترک را به درستی وارد کنید.');
                    return;
                }

                const oneThirdAssets = totalAssets / 3;
                let willAmount = this.utils.getFloat('will-amount');
                let notes = [];

                if (willAmount > oneThirdAssets) {
                    willAmount = oneThirdAssets;
                    notes.push(`مبلغ وصیت‌نامه بیش از یک‌سوم اموال بود و به ${this.utils.formatAmount(Math.round(oneThirdAssets))} تومان کاهش یافت.`);
                }

                const totalDeductions = willAmount + this.utils.getFloat('mahr-deduction') + this.utils.getFloat('nafaqeh-deduction') + this.utils.getFloat('burial-costs') + this.utils.getFloat('other-debts');
                const netAssets = totalAssets - totalDeductions;

                if (netAssets < 0) {
                    this.utils.showError(this.errorDiv, `ماترک برای پرداخت تمام دیون، هزینه‌ها و وصیت کافی نیست.<br>مبلغ کسری: ${this.utils.formatAmount(Math.abs(netAssets))} تومان`);
                    return;
                }

                const deceasedGender = this.utils.getVal('deceased-gender');
                const wifeCount = this.utils.getInt('wife-count');
                const fatherAlive = this.utils.isChecked('father-alive');
                const motherAlive = this.utils.isChecked('mother-alive');
                const sonCount = this.utils.getInt('son-count');
                const daughterCount = this.utils.getInt('daughter-count');
                const hasChildren = sonCount > 0 || daughterCount > 0;

                let remainingAssets = netAssets;
                let results = [];
                notes.push('دیون، هزینه‌ها و وصیت (تا سقف ۱/۳) قبل از تقسیم ارث از کل ماترک کسر شده است.');

                let spouseShareAmount = 0;
                if (deceasedGender === 'male' && wifeCount > 0) {
                    const frac = hasChildren ? {n:1,d:8} : {n:1,d:4};
                    spouseShareAmount = netAssets * (frac.n / frac.d);
                    const shareText = wifeCount > 1 ? `مشترکاً ${this.utils.createFraction(frac.n, frac.d)}` : this.utils.createFraction(frac.n, frac.d);
                    results.push({ heir: `همسر (${wifeCount} نفر)`, fraction: shareText, amount: spouseShareAmount });
                    if (wifeCount > 1) notes.push(`سهم بین ${wifeCount} همسر به تساوی تقسیم می‌شود.`);
                } else if (deceasedGender === 'female') {
                    const frac = hasChildren ? {n:1,d:4} : {n:1,d:2};
                    spouseShareAmount = netAssets * (frac.n / frac.d);
                    results.push({ heir: 'شوهر', fraction: this.utils.createFraction(frac.n, frac.d), amount: spouseShareAmount });
                }
                remainingAssets -= spouseShareAmount;

                if (hasChildren) {
                    if (fatherAlive) {
                        const amount = netAssets / 6;
                        results.push({ heir: 'پدر', fraction: this.utils.createFraction(1, 6), amount });
                        remainingAssets -= amount;
                    }
                    if (motherAlive) {
                        const amount = netAssets / 6;
                        results.push({ heir: 'مادر', fraction: this.utils.createFraction(1, 6), amount });
                        remainingAssets -= amount;
                    }
                } else {
                    if (motherAlive) {
                        let amount = netAssets / 3;
                        if (fatherAlive && deceasedGender === 'female' && (amount + spouseShareAmount) > netAssets) {
                            amount = netAssets / 6;
                            notes.push('سهم مادر به دلیل قاعده حاجب نقصانی به ۱/۶ کاهش یافت.');
                        }
                        results.push({ heir: 'مادر', fraction: this.utils.createFraction(1, 3), amount });
                        remainingAssets -= amount;
                    }
                    if (fatherAlive) {
                        results.push({ heir: 'پدر', fraction: 'الباقی', amount: remainingAssets });
                        remainingAssets = 0;
                    }
                }

                if (hasChildren) {
                    const totalChildShares = (sonCount * 2) + daughterCount;
                    if (totalChildShares > 0 && remainingAssets > 0.01) {
                        const singleShareValue = remainingAssets / totalChildShares;
                        if (sonCount > 0) {
                            results.push({ heir: `پسر (${sonCount} نفر)`, fraction: 'الباقی', amount: singleShareValue * 2 * sonCount });
                            notes.push(`هر پسر: ${this.utils.formatAmount(Math.round(singleShareValue * 2))} تومان`);
                        }
                        if (daughterCount > 0) {
                            results.push({ heir: `دختر (${daughterCount} نفر)`, fraction: 'الباقی', amount: singleShareValue * daughterCount });
                            notes.push(`هر دختر: ${this.utils.formatAmount(Math.round(singleShareValue))} تومان`);
                        }
                    }
                } else if (!fatherAlive && !motherAlive && remainingAssets > 0.01) {
                    const spouseResult = results.find(r => r.heir.includes('همسر') || r.heir.includes('شوهر'));
                    if (spouseResult) {
                        spouseResult.amount += remainingAssets;
                        spouseResult.fraction += ' + الباقی (رد)';
                        notes.push('باقیمانده ماترک به دلیل نبودن وراث دیگر، به همسر/شوهر رد می‌شود.');
                    }
                }
                this.displayResults(results, notes, { totalAssets, totalDeductions, netAssets });
            },
            displayResults: function(results, notes, summary) {
                let finalTotalDistributed = 0;
                let resultBodyHTML = '';
                results.forEach(res => {
                    finalTotalDistributed += res.amount;
                    resultBodyHTML += `<tr><td class="font-medium text-slate-200">${res.heir}</td><td class="font-mono text-lg">${res.fraction}</td><td class="font-mono text-lg text-green-400 font-semibold">${this.utils.formatAmount(Math.round(res.amount))}</td></tr>`;
                });
                let notesHTML = notes.map(note => `<li>${note}</li>`).join('');

                const content = `
                    <div class="result-card">
                        <div class="mb-8 p-6 bg-slate-900/50 border border-slate-700 rounded-lg">
                            <h3 class="text-xl font-bold text-center mb-4 text-slate-200">خلاصه محاسبات ماترک</h3>
                            <table class="w-full summary-table"><tbody>
                                <tr><td class="font-medium text-slate-300">کل ماترک (ناخالص):</td><td class="font-mono text-left text-slate-100">${this.utils.formatAmount(summary.totalAssets)} تومان</td></tr>
                                <tr><td class="font-medium text-slate-300">کسر دیون و وصیت:</td><td class="font-mono text-left text-red-400">( ${this.utils.formatAmount(summary.totalDeductions)} ) تومان</td></tr>
                                <tr class="border-t-2 border-slate-700"><td class="font-bold text-slate-100 pt-4">ماترک خالص (قابل تقسیم):</td><td class="font-mono text-left font-bold text-green-400 pt-4">${this.utils.formatAmount(Math.round(summary.netAssets))} تومان</td></tr>
                            </tbody></table>
                        </div>
                        <h2 class="text-2xl font-bold text-center mb-6 text-slate-100">نتیجه تقسیم ارث</h2>
                        <div class="overflow-x-auto">
                            <table class="min-w-full result-table">
                                <thead><tr><th class="font-semibold text-slate-300">وارث</th><th class="font-semibold text-slate-300">سهم (کسری)</th><th class="font-semibold text-slate-300">مبلغ سهم (تومان)</th></tr></thead>
                                <tbody>${resultBodyHTML}</tbody>
                                <tfoot><tr><td colspan="2" class="text-right pr-6">جمع کل</td><td class="text-cyan-400">${this.utils.formatAmount(Math.round(finalTotalDistributed))} تومان</td></tr></tfoot>
                            </table>
                        </div>
                        <div class="mt-6 p-4 bg-yellow-900/20 border-r-4 border-yellow-600 text-yellow-200 rounded-lg">
                            <h4 class="font-bold">توضیحات:</h4><ul class="list-disc list-inside mt-2 space-y-1">${notesHTML}</ul>
                        </div>
                    </div>`;
                this.utils.showResult(this.resultDiv, content);
            }
        },

        // --- MAHR MODULE ---
        mahr: {
            init: function(utils, cbiIndex) {
                this.utils = utils;
                this.cbiIndex = cbiIndex;
                this.errorDiv = document.getElementById('error-message-mahr');
                this.resultDiv = document.getElementById('result-section-mahr');
                this.mahrTypeSelect = document.getElementById('mahr-type');
                this.sections = {
                    coin_current_price: document.getElementById('mahr-coin-current-price-section'),
                    coin_indexed: document.getElementById('mahr-coin-indexed-section'),
                    cash_indexed: document.getElementById('mahr-cash-indexed-section')
                };

                this.populateYears();
                this.mahrTypeSelect.addEventListener('change', () => this.toggleSections());
                document.getElementById('calculate-btn-mahr').addEventListener('click', () => this.calculate());
                this.toggleSections();
            },
            populateYears: function() {
                const yearSelects = [document.getElementById('mahr-year-coin-indexed'), document.getElementById('mahr-year-cash-indexed')];
                const years = Object.keys(this.cbiIndex).sort((a, b) => b - a);
                yearSelects.forEach(select => {
                    if (!select) return;
                    years.forEach(year => select.add(new Option(`سال ${year}`, year)));
                });
            },
            toggleSections: function() {
                Object.values(this.sections).forEach(s => s.classList.add('hidden'));
                this.sections[this.mahrTypeSelect.value].classList.remove('hidden');
            },
            calculate: function() {
                this.utils.hideError(this.errorDiv);
                this.utils.hideResult(this.resultDiv);

                const mahrType = this.mahrTypeSelect.value;
                const currentPersianYear = 1405;
                const yearOfPayment = currentPersianYear - 1;
                let result = {};

                try {
                    if (mahrType === 'coin_current_price') {
                        const coinCount = this.utils.getInt('mahr-coin-count-current');
                        const coinPrice = this.utils.getFloat('mahr-coin-price-current');
                        if (coinCount <= 0 || coinPrice <= 0) throw new Error('لطفاً تعداد و قیمت روز سکه را به درستی وارد کنید.');
                        result.value = coinCount * coinPrice;
                        result.title = `ارزش روز ${this.utils.formatAmount(coinCount)} عدد سکه:`;
                        result.source = `(بر اساس قیمت هر سکه: ${this.utils.formatAmount(coinPrice)} تومان).`;
                    } else {
                        const contractYear = this.utils.getInt(mahrType === 'coin_indexed' ? 'mahr-year-coin-indexed' : 'mahr-year-cash-indexed');
                        const indexContract = this.cbiIndex[contractYear];
                        const indexPayment = this.cbiIndex[yearOfPayment];
                        if (!indexContract || !indexPayment) throw new Error(`اطلاعات شاخص برای سال عقد (${contractYear}) یا سال قبل از مطالبه (${yearOfPayment}) موجود نیست.`);

                        if (mahrType === 'coin_indexed') {
                            const coinCount = this.utils.getInt('mahr-coin-count-indexed');
                            const coinPriceContract = this.utils.getFloat('mahr-coin-price-contract');
                            if (coinCount <= 0 || coinPriceContract <= 0) throw new Error('لطفاً تعداد و قیمت سکه در سال عقد را به درستی وارد کنید.');
                            const mahrValueAtContract = coinCount * coinPriceContract;
                            result.value = (mahrValueAtContract * indexPayment) / indexContract;
                            result.title = `ارزش به‌روز شده معادل ریالی ${this.utils.formatAmount(coinCount)} سکه از سال ${contractYear}:`;
                            result.source = `ارزش در سال عقد: ${this.utils.formatAmount(mahrValueAtContract)} تومان. شاخص سال عقد: ${indexContract}, شاخص سال مطالبه: ${indexPayment}.`;
                        } else if (mahrType === 'cash_indexed') {
                            const mahrValue = this.utils.getFloat('mahr-value-contract');
                            if (mahrValue <= 0) throw new Error('لطفاً مبلغ مهریه را به درستی وارد کنید.');
                            result.value = (mahrValue * indexPayment) / indexContract;
                            result.title = `مبلغ مهریه به نرخ روز (سال ${currentPersianYear}):`;
                            result.source = `محاسبه بر اساس فرمول بانک مرکزی با شاخص سال عقد (${contractYear}) و سال قبل از مطالبه (${yearOfPayment}).`;
                        }
                    }
                    this.displayResult(result);
                } catch (e) {
                    this.utils.showError(this.errorDiv, e.message);
                }
            },
            displayResult: function(result) {
                const content = `<div class="result-card text-center"><p class="text-lg text-slate-300">${result.title}</p><p class="text-4xl font-bold text-green-400 mt-2">${this.utils.formatAmount(Math.round(result.value))} تومان</p><p class="text-sm text-slate-400 mt-4">${result.source}</p></div>`;
                this.utils.showResult(this.resultDiv, content);
            }
        },

        ujrat: {
             init: function(utils, minWages) {
                this.utils = utils;
                this.minWages = minWages;
                this.errorDiv = document.getElementById('error-message-ujrat');
                this.resultDiv = document.getElementById('result-section-ujrat');
                this.startYearSelect = document.getElementById('ujrat-start-year');
                this.endYearSelect = document.getElementById('ujrat-end-year');

                this.populateYears();
                document.getElementById('calculate-btn-ujrat').addEventListener('click', () => this.calculate());
            },
            populateYears: function() {
                 Object.keys(this.minWages).sort((a,b) => b-a).forEach(year => {
                    this.startYearSelect.add(new Option(`سال ${year}`, year));
                    this.endYearSelect.add(new Option(`سال ${year}`, year));
                });
            },
            calculate: function() {
                this.utils.hideError(this.errorDiv);
                this.utils.hideResult(this.resultDiv);
                const startYear = this.utils.getInt('ujrat-start-year');
                const endYear = this.utils.getInt('ujrat-end-year');

                if (startYear >= endYear) {
                    this.utils.showError(this.errorDiv, 'سال پایان باید بعد از سال شروع باشد.');
                    return;
                }

                let totalUjrat = 0;
                for (let year = startYear; year < endYear; year++) {
                    if (this.minWages[year]) {
                        totalUjrat += this.minWages[year];
                    }
                }

                const title = `برآورد اجرت المثل برای ${endYear - startYear} سال (از ${startYear} تا ${endYear}):`;
                const value = `${this.utils.formatAmount(Math.round(totalUjrat))} تومان`;
                const content = `<div class="result-card text-center"><p class="text-lg text-slate-300">${title}</p><p class="text-4xl font-bold text-yellow-400 mt-2">${value}</p></div>`;
                this.utils.showResult(this.resultDiv, content);
            }
        },

        costs: {
            init: function(utils) {
                this.utils = utils;
                this.resultDiv = document.getElementById('result-section-costs');
                this.costsTypeSelect = document.getElementById('costs-type');
                this.sections = {
                    financial_claim: document.getElementById('costs-financial-section'),
                    bounced_check: document.getElementById('costs-check-section'),
                    other_services: document.getElementById('costs-other-section')
                };

                this.costsTypeSelect.addEventListener('change', () => this.toggleSections());
                document.getElementById('calculate-btn-costs').addEventListener('click', () => this.calculate());
                this.toggleSections();
            },
            toggleSections: function() {
                Object.values(this.sections).forEach(s => s.classList.add('hidden'));
                if (this.sections[this.costsTypeSelect.value]) {
                    this.sections[this.costsTypeSelect.value].classList.remove('hidden');
                }
            },
            calculate: function() {
                this.utils.hideResult(this.resultDiv);
                const type = this.costsTypeSelect.value;
                let cost = 0;
                let title = 'هزینه:';

                if (type === 'financial_claim') {
                    const stage = this.utils.getVal('costs-financial-stage');
                    const value = this.utils.getFloat('costs-financial-value');
                    const stageText = document.querySelector('#costs-financial-stage option:checked').textContent;
                    title = `هزینه دادرسی دعوای مالی در مرحله ${stageText}:`;

                    let rate1, rate2, threshold;
                    if (stage === 'badavi') { rate1 = 0.025; rate2 = 0.035; }
                    else if (stage === 'tajdidnazar') { rate1 = 0.045; rate2 = 0.045; }
                    else { rate1 = 0.055; rate2 = 0.055; }
                    threshold = 200000000;

                    cost = value <= threshold ? value * rate1 : (threshold * rate1) + ((value - threshold) * rate2);

                } else if (type === 'bounced_check') {
                    title = 'هزینه شکایت کیفری چک بلامحل:';
                    const value = this.utils.getFloat('costs-check-value');
                    if (value <= 1000000) cost = 60000;
                    else if (value <= 10000000) cost = 300000;
                    else if (value <= 100000000) cost = 400000;
                    else cost = value * 0.001;

                } else if (type === 'non_financial_claim') {
                    title = 'هزینه دادرسی دعاوی غیرمالی:';
                    cost = 1800000;
                } else if (type === 'other_services') {
                    const serviceSelect = document.getElementById('costs-other-service-type');
                    cost = parseFloat(serviceSelect.value);
                    title = `هزینه ${serviceSelect.options[serviceSelect.selectedIndex].text}:`;
                }

                const content = `<div class="result-card text-center"><p class="text-lg text-slate-300">${title}</p><p class="text-4xl font-bold text-purple-400 mt-2">${this.utils.formatAmount(Math.round(cost))} ریال</p></div>`;
                this.utils.showResult(this.resultDiv, content);
            }
        },

        nafaqeh: {
            init: function(utils) {
                this.utils = utils;
                this.inputs = document.querySelectorAll('.nafaqeh-input');
                this.resultDiv = document.getElementById('result-section-nafaqeh');
                this.inputs.forEach(input => input.addEventListener('keyup', () => this.calculate()));
                this.calculate(); // Initial calculation
            },
            calculate: function() {
                let total = 0;
                this.inputs.forEach(input => {
                    total += parseFloat(input.value.replace(/,/g, '')) || 0;
                });
                const title = `مجموع هزینه‌های برآورد شده ماهیانه:`;
                const value = `${this.utils.formatAmount(total)} تومان`;
                const content = `<div class="result-card text-center"><p class="text-lg text-slate-300">${title}</p><p class="text-4xl font-bold text-pink-400 mt-2">${value}</p></div>`;
                this.utils.showResult(this.resultDiv, content);
            }
        }
    };

    EstilaCalculator.init();
});
