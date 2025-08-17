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
            showResult: (el) => {
                el.classList.remove('hidden');
                setTimeout(() => {
                    el.classList.add('visible');
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 50);
            },
            hideResult: (el) => {
                el.classList.remove('visible');
                setTimeout(() => {
                    el.classList.add('hidden');
                }, 500);
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

            document.querySelectorAll('input[type="text"], input[type="number"]').forEach(input => {
                if(input.id.includes('count') || input.id.includes('year')) return;
                input.addEventListener('keyup', () => this.utils.formatCurrency(input));
            });
        },

        // --- INHERITANCE MODULE ---
        inheritance: {
            chart: null,
            init: function(utils) {
                this.utils = utils;
                this.errorDiv = document.getElementById('error-message-inheritance');
                this.resultDiv = document.getElementById('result-section-inheritance');
                this.tableContainer = document.getElementById('inheritance-table-container');

                document.getElementById('calculate-btn-inheritance').addEventListener('click', () => this.calculate());
                document.getElementById('reset-btn-inheritance').addEventListener('click', () => this.reset());

                // Heir class visibility logic
                const heirClassSelect = document.getElementById('heir-class');
                heirClassSelect.addEventListener('change', (e) => {
                    document.querySelectorAll('.heir-class-section').forEach(s => s.classList.add('hidden'));
                    document.getElementById(`heirs-class-${e.target.value}`).classList.remove('hidden');
                });

                // Wife count visibility
                const genderSelect = document.getElementById('deceased-gender');
                genderSelect.addEventListener('change', (e) => {
                    document.getElementById('wife-count-container').style.display = e.target.value === 'male' ? 'block' : 'none';
                });
            },
            reset: function() {
                document.getElementById('form-section-inheritance').reset();
                 document.getElementById('wife-count-container').style.display = 'block';
                document.querySelectorAll('.heir-class-section').forEach((s, i) => {
                    s.classList.toggle('hidden', i !== 0);
                });
                this.utils.hideResult(this.resultDiv);
                this.utils.hideError(this.errorDiv);
                if (this.chart) {
                    this.chart.destroy();
                    this.chart = null;
                }
            },
            calculate: function() {
                this.utils.hideError(this.errorDiv);
                this.utils.hideResult(this.resultDiv);
                if (this.chart) this.chart.destroy();

                // --- 1. GET COMMON VALUES ---
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
                let netAssets = totalAssets - totalDeductions;

                if (netAssets < 0) {
                    this.utils.showError(this.errorDiv, `ماترک برای پرداخت تمام دیون، هزینه‌ها و وصیت کافی نیست.<br>مبلغ کسری: ${this.utils.formatAmount(Math.abs(netAssets))} تومان`);
                    return;
                }
                notes.push('دیون، هزینه‌ها و وصیت (تا سقف ۱/۳) قبل از تقسیم ارث از کل ماترک کسر شده است.');

                const deceasedGender = this.utils.getVal('deceased-gender');
                const heirClass = this.utils.getVal('heir-class');
                let results = [];

                // --- 2. SPOUSE SHARE ---
                const sonCount = this.utils.getInt('son-count');
                const daughterCount = this.utils.getInt('daughter-count');
                const hasChildren = heirClass === '1' && (sonCount > 0 || daughterCount > 0);
                let spouseShareAmount = 0;

                if (deceasedGender === 'male') {
                    const wifeCount = this.utils.getInt('wife-count');
                    if (wifeCount > 0) {
                        const frac = hasChildren ? {n:1,d:8} : {n:1,d:4};
                        spouseShareAmount = netAssets * (frac.n / frac.d);
                        const shareText = wifeCount > 1 ? `مشترکاً ${this.utils.createFraction(frac.n, frac.d)}` : this.utils.createFraction(frac.n, frac.d);
                        results.push({ heir: `همسر (${wifeCount} نفر)`, fraction: shareText, amount: spouseShareAmount });
                        if (wifeCount > 1) notes.push(`سهم بین ${wifeCount} همسر به تساوی تقسیم می‌شود.`);
                    }
                } else { // Deceased is female, has one husband
                    const frac = hasChildren ? {n:1,d:4} : {n:1,d:2};
                    spouseShareAmount = netAssets * (frac.n / frac.d);
                    results.push({ heir: 'شوهر', fraction: this.utils.createFraction(frac.n, frac.d), amount: spouseShareAmount });
                }
                netAssets -= spouseShareAmount;

                // --- 3. CALCULATE BASED ON HEIR CLASS ---
                if (heirClass === '1') this.calculateClass1(netAssets, results, notes);
                else if (heirClass === '2') this.calculateClass2(netAssets, results, notes);
                else if (heirClass === '3') this.calculateClass3(netAssets, results, notes);

                this.displayResults(results, notes, { totalAssets, totalDeductions, netAssets: totalAssets - totalDeductions });
            },

            calculateClass1: function(assets, results, notes) {
                const fatherAlive = this.utils.isChecked('father-alive');
                const motherAlive = this.utils.isChecked('mother-alive');
                const sonCount = this.utils.getInt('son-count');
                const daughterCount = this.utils.getInt('daughter-count');
                const hasChildren = sonCount > 0 || daughterCount > 0;

                let remaining = assets;
                if (hasChildren) {
                    if (fatherAlive) { const amount = assets / 6; results.push({ heir: 'پدر', fraction: this.utils.createFraction(1, 6), amount }); remaining -= amount; }
                    if (motherAlive) { const amount = assets / 6; results.push({ heir: 'مادر', fraction: this.utils.createFraction(1, 6), amount }); remaining -= amount; }
                } else {
                    if (motherAlive) { const amount = assets / 3; results.push({ heir: 'مادر', fraction: this.utils.createFraction(1, 3), amount }); remaining -= amount; }
                    if (fatherAlive) { results.push({ heir: 'پدر', fraction: 'الباقی', amount: remaining }); remaining = 0; }
                }

                if (hasChildren) {
                    const totalChildShares = (sonCount * 2) + daughterCount;
                    if (totalChildShares > 0 && remaining > 0.01) {
                        const singleShareValue = remaining / totalChildShares;
                        if (sonCount > 0) results.push({ heir: `پسر (${sonCount} نفر)`, fraction: 'الباقی', amount: singleShareValue * 2 * sonCount });
                        if (daughterCount > 0) results.push({ heir: `دختر (${daughterCount} نفر)`, fraction: 'الباقی', amount: singleShareValue * daughterCount });
                    }
                }
            },

            calculateClass2: function(assets, results, notes) {
                 notes.push("محاسبات طبقه دوم بر اساس قوانین اصلی است و شامل حالت‌های خاص و پیچیده (مانند اجتماع اجداد و کلاله) نمی‌شود.");
                 // Simplified logic
                 const pgf = this.utils.isChecked('paternal-grandfather-alive');
                 const pgm = this.utils.isChecked('paternal-grandmother-alive');
                 const mgf = this.utils.isChecked('maternal-grandfather-alive');
                 const mgm = this.utils.isChecked('maternal-grandmother-alive');
                 const fullBro = this.utils.getInt('full-brother-count');
                 const fullSis = this.utils.getInt('full-sister-count');
                 const matSib = this.utils.getInt('maternal-sibling-count');

                 let remaining = assets;
                 // Maternal siblings (Kalalah Ummi)
                 if (matSib > 0) {
                     const frac = matSib === 1 ? 1/6 : 1/3;
                     const amount = assets * frac;
                     results.push({ heir: `خواهر و برادر مادری (${matSib} نفر)`, fraction: this.utils.createFraction(matSib === 1 ? 1:1, matSib === 1 ? 6:3), amount });
                     remaining -= amount;
                 }

                 // Grandparents take priority over siblings in this simplified model
                 const paternalGrandparents = (pgf ? 1:0) + (pgm ? 1:0);
                 const maternalGrandparents = (mgf ? 1:0) + (mgm ? 1:0);

                 if (paternalGrandparents + maternalGrandparents > 0) {
                     const paternalSideShare = remaining * 2/3;
                     const maternalSideShare = remaining * 1/3;
                     if (pgf && pgm) {
                         results.push({ heir: 'جد پدری', fraction: '', amount: paternalSideShare * 2/3 });
                         results.push({ heir: 'جده پدری', fraction: '', amount: paternalSideShare * 1/3 });
                     } else if (pgf) results.push({ heir: 'جد پدری', fraction: '', amount: paternalSideShare });
                     else if (pgm) results.push({ heir: 'جده پدری', fraction: '', amount: paternalSideShare });

                     if (mgf && mgm) {
                         results.push({ heir: 'جد مادری', fraction: '', amount: maternalSideShare / 2 });
                         results.push({ heir: 'جده مادری', fraction: '', amount: maternalSideShare / 2 });
                     } else if (mgf) results.push({ heir: 'جد مادری', fraction: '', amount: maternalSideShare });
                     else if (mgm) results.push({ heir: 'جده مادری', fraction: '', amount: maternalSideShare });

                 } else if (fullBro + fullSis > 0) { // Full siblings
                    const totalShares = (fullBro * 2) + fullSis;
                    const singleShare = remaining / totalShares;
                    if (fullBro > 0) results.push({ heir: `برادر ابوینی (${fullBro} نفر)`, fraction: 'الباقی', amount: singleShare * 2 * fullBro });
                    if (fullSis > 0) results.push({ heir: `خواهر ابوینی (${fullSis} نفر)`, fraction: 'الباقی', amount: singleShare * fullSis });
                 }
            },

            calculateClass3: function(assets, results, notes) {
                 const paternalUncles = this.utils.getInt('paternal-uncle-count');
                 const paternalAunts = this.utils.getInt('paternal-aunt-count');
                 const maternalUncles = this.utils.getInt('maternal-uncle-count');
                 const maternalAunts = this.utils.getInt('maternal-aunt-count');

                 const paternalSideHeirs = paternalUncles + paternalAunts;
                 const maternalSideHeirs = maternalUncles + maternalAunts;

                 if (paternalSideHeirs === 0 && maternalSideHeirs === 0) return;

                 const paternalSideShare = assets * 2/3;
                 const maternalSideShare = assets * 1/3;

                 if (paternalSideHeirs > 0) {
                     const totalShares = (paternalUncles * 2) + paternalAunts;
                     const singleShare = paternalSideShare / totalShares;
                     if(paternalUncles > 0) results.push({ heir: `عمو (${paternalUncles} نفر)`, fraction: '', amount: singleShare * 2 * paternalUncles });
                     if(paternalAunts > 0) results.push({ heir: `عمه (${paternalAunts} نفر)`, fraction: '', amount: singleShare * paternalAunts });
                 }

                 if (maternalSideHeirs > 0) {
                     const singleShare = maternalSideShare / maternalSideHeirs;
                     if(maternalUncles > 0) results.push({ heir: `دایی (${maternalUncles} نفر)`, fraction: '', amount: singleShare * maternalUncles });
                     if(maternalAunts > 0) results.push({ heir: `خاله (${maternalAunts} نفر)`, fraction: '', amount: singleShare * maternalAunts });
                 }
            },

            displayResults: function(results, notes, summary) {
                const chartData = {
                    labels: results.map(r => r.heir),
                    datasets: [{
                        data: results.map(r => r.amount),
                        backgroundColor: ['#22d3ee', '#818cf8', '#f472b6', '#fbbf24', '#4ade80', '#a78bfa', '#60a5fa', '#f87171'],
                        borderColor: '#1e293b',
                    }]
                };

                let finalTotalDistributed = 0;
                let resultBodyHTML = '';
                results.forEach(res => {
                    finalTotalDistributed += res.amount;
                    resultBodyHTML += `<tr><td class="font-medium text-slate-200">${res.heir}</td><td class="font-mono text-lg">${res.fraction}</td><td class="font-mono text-lg text-green-400 font-semibold">${this.utils.formatAmount(Math.round(res.amount))}</td></tr>`;
                });
                let notesHTML = notes.map(note => `<li>${note}</li>`).join('');

                const tableContent = `
                    <div class="mb-8 p-6 bg-slate-900/50 border border-slate-700 rounded-lg">
                        <h3 class="text-xl font-bold text-center mb-4 text-slate-200">خلاصه محاسبات ماترک</h3>
                        <table class="w-full summary-table"><tbody>
                            <tr><td class="font-medium text-slate-300">کل ماترک (ناخالص):</td><td class="font-mono text-left text-slate-100">${this.utils.formatAmount(summary.totalAssets)} تومان</td></tr>
                            <tr><td class="font-medium text-slate-300">کسر دیون و وصیت:</td><td class="font-mono text-left text-red-400">( ${this.utils.formatAmount(summary.totalDeductions)} ) تومان</td></tr>
                            <tr class="border-t-2 border-slate-700"><td class="font-bold text-slate-100 pt-4">ماترک خالص (قابل تقسیم):</td><td class="font-mono text-left font-bold text-green-400 pt-4">${this.utils.formatAmount(Math.round(summary.netAssets))} تومان</td></tr>
                        </tbody></table>
                    </div>
                    <h2 class="text-2xl font-bold text-center mb-6 text-slate-100">جدول دقیق تقسیم ارث</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full result-table">
                            <thead><tr><th class="font-semibold text-slate-300">وارث</th><th class="font-semibold text-slate-300">سهم (کسری)</th><th class="font-semibold text-slate-300">مبلغ سهم (تومان)</th></tr></thead>
                            <tbody>${resultBodyHTML}</tbody>
                            <tfoot><tr><td colspan="2" class="text-right pr-6">جمع کل</td><td class="text-cyan-400">${this.utils.formatAmount(Math.round(finalTotalDistributed))} تومان</td></tr></tfoot>
                        </table>
                    </div>
                    <div class="mt-6 p-4 bg-yellow-900/20 border-r-4 border-yellow-600 text-yellow-200 rounded-lg">
                        <h4 class="font-bold">توضیحات:</h4><ul class="list-disc list-inside mt-2 space-y-1">${notesHTML}</ul>
                    </div>`;

                this.tableContainer.innerHTML = tableContent;
                this.utils.showResult(this.resultDiv);
                this.renderChart(chartData);
            },
            renderChart: function(data) {
                const ctx = document.getElementById('inheritance-chart').getContext('2d');
                this.chart = new Chart(ctx, {
                    type: 'pie',
                    data: data,
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    color: '#cbd5e1',
                                    font: { family: 'Vazirmatn' }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: (context) => {
                                        let label = context.label || '';
                                        if (label) { label += ': '; }
                                        const value = context.parsed;
                                        const total = context.chart.getDatasetMeta(0).total;
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(2) : 0;
                                        label += `${this.utils.formatAmount(Math.round(value))} تومان (${percentage}%)`;
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
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
            chart: null,
            init: function(utils) {
                this.utils = utils;
                this.inputs = document.querySelectorAll('.nafaqeh-input');
                this.resultDiv = document.getElementById('result-section-nafaqeh');
                this.inputs.forEach(input => input.addEventListener('keyup', () => this.calculate()));
                this.calculate();
            },
            calculate: function() {
                const data = [
                    { label: 'مسکن', value: this.utils.getFloat('nafaqeh-housing') },
                    { label: 'خوراک', value: this.utils.getFloat('nafaqeh-food') },
                    { label: 'پوشاک', value: this.utils.getFloat('nafaqeh-clothing') },
                    { label: 'درمان', value: this.utils.getFloat('nafaqeh-health') },
                    { label: 'رفت و آمد', value: this.utils.getFloat('nafaqeh-transport') },
                    { label: 'سایر', value: this.utils.getFloat('nafaqeh-other') },
                ];

                const total = data.reduce((sum, item) => sum + item.value, 0);

                if (total > 0) {
                    this.utils.showResult(this.resultDiv);
                    this.renderChart(data, total);
                } else {
                    this.utils.hideResult(this.resultDiv);
                }
            },
            renderChart: function(data, total) {
                if (this.chart) {
                    this.chart.destroy();
                }
                const ctx = document.getElementById('nafaqeh-chart').getContext('2d');
                this.chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.map(d => d.label),
                        datasets: [{
                            label: 'هزینه (تومان)',
                            data: data.map(d => d.value),
                            backgroundColor: ['#38bdf8', '#818cf8', '#e879f9', '#facc15', '#4ade80', '#9ca3af'],
                            borderRadius: 5,
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            title: {
                                display: true,
                                text: `مجموع نفقه ماهانه: ${this.utils.formatAmount(total)} تومان`,
                                color: '#f1f5f9',
                                font: { size: 18, family: 'Vazirmatn' }
                            }
                        },
                        scales: {
                            x: {
                                ticks: { color: '#94a3b8' },
                                grid: { color: 'rgba(148, 163, 184, 0.2)' }
                            },
                            y: {
                                ticks: { color: '#94a3b8', font: { family: 'Vazirmatn' } }
                            }
                        }
                    }
                });
            }
        }
    };

    EstilaCalculator.init();
});
