// Global Functions
function showToast(message, type = 'info') {
    const toastContainer = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type} pointer-events-auto`;
    toast.textContent = message;
    toastContainer.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 100);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function showImageModal(src) {
    document.getElementById('modal-image').src = src;
    document.getElementById('image-modal').classList.add('show');
}

function closeImageModal() {
    document.getElementById('image-modal').classList.remove('show');
}

let notificationPollInterval;
function startNotificationPolling() {
    checkUnreadNotifications();
    notificationPollInterval = setInterval(checkUnreadNotifications, 30000);
}

async function checkUnreadNotifications() {
    const notificationBadge = document.getElementById('notification-badge');
    if (!notificationBadge) return;
    try {
        const response = await fetch('index.php?action=get_unread_notifications_count');
        const result = await response.json();
        if (result.success && result.count > 0) {
            notificationBadge.textContent = result.count;
            notificationBadge.classList.remove('hidden');
        } else {
            notificationBadge.classList.add('hidden');
        }
    } catch (error) {
        console.error("Error checking notifications:", error);
    }
}

async function deleteCase(caseId, clientEmail) {
    if (!confirm(`آیا از حذف پرونده مربوط به "${clientEmail}" مطمئن هستید؟`)) return;
    try {
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_case', case_id: caseId })
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.message || 'خطا در حذف پرونده.', 'error');
        }
    } catch (error) {
        showToast('خطای شبکه.', 'error');
    }
}

// Create New Case Modal Logic
function createNewCase() {
    const modalContainer = document.getElementById('modal-container');
    const template = document.getElementById('create-case-modal-template');
    modalContainer.innerHTML = '';
    modalContainer.appendChild(template.content.cloneNode(true));
    
    const wizardState = { currentStep: 1 };
    const form = document.getElementById('create-case-form');
    const nextBtn = document.getElementById('next-step-btn');
    const prevBtn = document.getElementById('prev-step-btn');
    const submitBtn = document.getElementById('create-case-submit-btn');
    
    const updateWizardUI = () => {
        document.querySelectorAll('.wizard-step').forEach(step => {
            const stepNum = parseInt(step.dataset.step);
            step.classList.remove('active', 'completed');
            if (stepNum < wizardState.currentStep) step.classList.add('completed');
            if (stepNum === wizardState.currentStep) step.classList.add('active');
        });
        
        document.querySelectorAll('.wizard-content').forEach(content => {
            content.classList.toggle('hidden', parseInt(content.dataset.stepContent) !== wizardState.currentStep);
        });
        
        prevBtn.classList.toggle('hidden', wizardState.currentStep === 1);
        nextBtn.classList.toggle('hidden', wizardState.currentStep === 4);
        submitBtn.classList.toggle('hidden', wizardState.currentStep !== 4);
    };
    
    const validateStep = (step) => {
        if (step === 1) {
            if (!form.elements['select-client'].value) {
                showToast('لطفا یک موکل را انتخاب کنید.', 'error');
                return false;
            }
        }
        if (step === 2) {
             if (!form.elements['new-case-name'].value.trim()) {
                showToast('لطفا عنوان پرونده را وارد کنید.', 'error');
                return false;
            }
        }
        return true;
    };
    
    const populateReview = () => {
        const reviewContainer = document.getElementById('review-container');
        const clientSelect = form.elements['select-client'];
        const clientName = clientSelect.options[clientSelect.selectedIndex].text;
        const jalaliDate = form.elements['new-next-hearing-date'].value ? format_jalali_date(form.elements['new-next-hearing-date'].value) : 'ثبت نشده';
        reviewContainer.innerHTML = `
            <p class="text-gray-300"><strong class="text-white">موکل:</strong> ${clientName}</p>
            <p class="text-gray-300"><strong class="text-white">عنوان:</strong> ${form.elements['new-case-name'].value}</p>
            <p class="text-gray-300"><strong class="text-white">وضعیت:</strong> ${form.elements['new-case-status'].value}</p>
            <p class="text-gray-300"><strong class="text-white">دادگاه:</strong> ${form.elements['new-court-name'].value || 'ثبت نشده'}</p>
            <p class="text-gray-300"><strong class="text-white">شماره پرونده:</strong> ${form.elements['new-case-number'].value || 'ثبت نشده'}</p>
            <p class="text-gray-300"><strong class="text-white">جلسه بعدی:</strong> ${jalaliDate}</p>
        `;
    };
    
    nextBtn.addEventListener('click', () => {
        if (validateStep(wizardState.currentStep)) {
            wizardState.currentStep++;
            if (wizardState.currentStep === 4) populateReview();
            updateWizardUI();
        }
    });
    
    prevBtn.addEventListener('click', () => {
        wizardState.currentStep--;
        updateWizardUI();
    });
    
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (wizardState.currentStep !== 4) {
            showToast('لطفاً تمامی مراحل را کامل کنید.', 'error');
            return;
        }
        
        const formData = new FormData(form);
        formData.append('action', 'update_case');
        
        try {
            const response = await fetch('index.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) {
                showToast('پرونده با موفقیت ایجاد شد!', 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message || 'خطا در ایجاد پرونده.', 'error');
            }
        } catch (error) {
            showToast('خطای شبکه.', 'error');
        }
    });
    
    document.getElementById('close-create-modal-btn').onclick = () => { modalContainer.innerHTML = ''; };
}

function createCaseForClient(clientId, clientEmail) {
    createNewCase();
    setTimeout(() => {
        const clientSelect = document.getElementById('select-client');
        if (clientSelect) {
            if (!clientSelect.querySelector(`option[value="${clientId}"]`)) {
                const option = document.createElement('option');
                option.value = clientId;
                option.textContent = clientEmail;
                clientSelect.appendChild(option);
            }
            clientSelect.value = clientId;
            clientSelect.disabled = true;
        }
    }, 0);
}

async function openCaseModal(clientId, clientEmail) {
    const modalContainer = document.getElementById('modal-container');
    const template = document.getElementById('case-management-modal-template');
    modalContainer.innerHTML = '';
    const clonedTemplate = template.content.cloneNode(true);
    modalContainer.appendChild(clonedTemplate);
    
    document.getElementById('close-modal-btn').onclick = () => {
        modalContainer.innerHTML = '';
    };
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            modalContainer.innerHTML = '';
        }
    });
    
    document.getElementById('modal-client-email').textContent = clientEmail;
    document.getElementById('modal-client-id').value = clientId;
    document.getElementById('summarize-case-btn').onclick = () => generateCaseSummary(clientId, 'admin');
    
    try {
        const response = await fetch(`index.php?action=get_case_details&client_id=${clientId}`);
        const result = await response.json();
        if (result.success) {
            document.getElementById('case-name').value = result.case.case_name;
            document.getElementById('case-status').value = result.case.status;
            document.getElementById('case-description').value = result.case.description || '';
            document.getElementById('court-name').value = result.case.court_name || '';
            document.getElementById('case-number').value = result.case.case_number || '';
            document.getElementById('modal-case-id').value = result.case.id || '';
            if (result.case.next_hearing_date) {
                document.getElementById('next-hearing-date').value = result.case.next_hearing_date;
            }
            renderCaseHistory(result.history);
        }
    } catch (error) {
        showToast('خطا در بارگذاری اطلاعات پرونده.', 'error');
    }
    
    document.getElementById('case-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        formData.append('action', 'update_case');
        
        const saveBtn = document.getElementById('save-case-btn');
        const btnText = document.getElementById('save-case-btn-text');
        const spinner = document.getElementById('save-case-spinner');
        const statusEl = document.getElementById('file-upload-status');
        
        saveBtn.disabled = true;
        btnText.classList.add('hidden');
        spinner.classList.remove('hidden');
        statusEl.textContent = 'در حال پردازش...';
        statusEl.className = 'text-xs mt-2 min-h-5 text-blue-400';
        
        try {
            const response = await fetch('index.php', { 
                method: 'POST', 
                body: formData 
            });
            const result = await response.json();
            if (result.success) {
                statusEl.className = 'text-xs mt-2 min-h-5 text-green-400';
                statusEl.textContent = '✅ عملیات با موفقیت انجام شد.';
                showToast(result.message, 'success');
                
                const refreshResponse = await fetch(`index.php?action=get_case_details&client_id=${clientId}`);
                const refreshResult = await refreshResponse.json();
                if (refreshResult.success) {
                    renderCaseHistory(refreshResult.history);
                    form.querySelector('#lawyer-opinion').value = '';
                    form.querySelector('#case-file-input').value = '';
                }
            } else {
                statusEl.className = 'text-xs mt-2 min-h-5 text-red-400';
                statusEl.textContent = '❌ ' + (result.message || 'خطا در ذخیره‌سازی.');
                showToast(result.message || 'خطا در ذخیره‌سازی.', 'error');
            }
        } catch (err) {
            statusEl.className = 'text-xs mt-2 min-h-5 text-red-400';
            statusEl.textContent = '❌ خطای شبکه.';
            showToast('خطای شبکه. لطفاً دوباره تلاش کنید.', 'error');
        } finally {
            saveBtn.disabled = false;
            btnText.classList.remove('hidden');
            spinner.classList.add('hidden');
            setTimeout(() => {
                if (statusEl) statusEl.textContent = '';
            }, 3000);
        }
    });
}

// Helper function to format Jalali date in JavaScript (for the review step)
function format_jalali_date(gregorianDateStr) {
    if (!gregorianDateStr) return 'ثبت نشده';
    const [year, month, day] = gregorianDateStr.split('-').map(Number);
    const jalali = gregorian_to_jalali(year, month, day);
    return `${jalali[0]}/${String(jalali[1]).padStart(2, '0')}/${String(jalali[2]).padStart(2, '0')}`;
}

// JavaScript implementation of the gregorian_to_jalali function for client-side use
function gregorian_to_jalali(gy, gm, gd) {
    const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    let jy = (gy <= 1600) ? 0 : 979;
    gy -= (gy <= 1600) ? 621 : 1600;
    const gy2 = (gm > 2) ? (gy + 1) : gy;
    let days = (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
    jy += 621;
    jy += 33 * Math.floor(days / 12053);
    days %= 12053;
    jy += 4 * Math.floor(days / 1461);
    days %= 1461;
    jy += Math.floor((days - 1) / 365);
    if (days > 365) days = (days - 1) % 365;
    const jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
    const jd = (days < 186) ? 1 + (days % 31) : 1 + ((days - 186) % 30);
    return [jy, jm, jd];
}