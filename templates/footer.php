    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.5.28/dist/jspdf.plugin.autotable.min.js"></script>
    <script>
        (function () {
            function isVisibleField(field) {
                if (!field || field.disabled) {
                    return false;
                }
                if (field.type === 'hidden' || field.type === 'button' || field.type === 'submit' || field.type === 'reset') {
                    return false;
                }
                return true;
            }

            function ensureFeedback(field, message) {
                let feedback = field.parentElement.querySelector('.invalid-feedback[data-auto="1"]');
                if (!feedback) {
                    feedback = document.createElement('div');
                    feedback.className = 'invalid-feedback';
                    feedback.setAttribute('data-auto', '1');
                    field.parentElement.appendChild(feedback);
                }
                feedback.textContent = message;
            }

            function getMessage(field) {
                const label = field.getAttribute('data-label') || field.getAttribute('name') || 'This field';
                const validity = field.validity;

                if (validity.valueMissing) {
                    return label + ' is required.';
                }
                if (validity.typeMismatch) {
                    if (field.type === 'email') {
                        return 'Enter a valid email address.';
                    }
                    if (field.type === 'url') {
                        return 'Enter a valid URL.';
                    }
                    return 'Enter a valid value.';
                }
                if (validity.patternMismatch) {
                    return 'Please match the required format.';
                }
                if (validity.tooShort) {
                    return label + ' is too short.';
                }
                if (validity.tooLong) {
                    return label + ' is too long.';
                }
                if (validity.rangeUnderflow) {
                    return label + ' is below the minimum allowed value.';
                }
                if (validity.rangeOverflow) {
                    return label + ' exceeds the maximum allowed value.';
                }
                if (validity.stepMismatch) {
                    return 'Please enter a valid increment value.';
                }
                if (validity.badInput) {
                    return 'Please enter a valid number.';
                }
                return 'Please check this field.';
            }

            function validateField(field) {
                if (!isVisibleField(field)) {
                    return true;
                }

                if (!field.checkValidity()) {
                    ensureFeedback(field, getMessage(field));
                    field.classList.add('is-invalid');
                    field.classList.remove('is-valid');
                    return false;
                }

                field.classList.remove('is-invalid');
                if (field.value !== '') {
                    field.classList.add('is-valid');
                } else {
                    field.classList.remove('is-valid');
                }
                return true;
            }

            function attachFormValidation(form) {
                if (!form || form.dataset.validationAttached === '1' || form.dataset.skipValidation === '1') {
                    return;
                }

                form.dataset.validationAttached = '1';
                form.setAttribute('novalidate', 'novalidate');

                form.addEventListener('submit', function (event) {
                    const fields = Array.from(form.querySelectorAll('input, select, textarea'));
                    let valid = true;

                    fields.forEach(function (field) {
                        if (!validateField(field)) {
                            valid = false;
                        }
                    });

                    if (!valid) {
                        event.preventDefault();
                        event.stopPropagation();
                        const firstInvalid = form.querySelector('.is-invalid');
                        if (firstInvalid) {
                            firstInvalid.focus();
                        }
                    }
                });

                form.addEventListener('input', function (event) {
                    const field = event.target;
                    if (field && field.matches('input, select, textarea')) {
                        validateField(field);
                    }
                });

                form.addEventListener('change', function (event) {
                    const field = event.target;
                    if (field && field.matches('input, select, textarea')) {
                        validateField(field);
                    }
                });
            }

            function initValidation() {
                document.querySelectorAll('form').forEach(attachFormValidation);
            }

            document.addEventListener('DOMContentLoaded', initValidation);

            const observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (!(node instanceof Element)) {
                            return;
                        }
                        if (node.matches && node.matches('form')) {
                            attachFormValidation(node);
                        }
                        node.querySelectorAll && node.querySelectorAll('form').forEach(attachFormValidation);
                    });
                });
            });

            observer.observe(document.body, { childList: true, subtree: true });
        })();
    </script>

    <div class="modal fade" id="appConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-warning-subtle">
                    <h5 class="modal-title" id="appConfirmModalTitle">Please Confirm</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0" id="appConfirmModalMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="appConfirmModalOk">Yes, Continue</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            let pendingForm = null;

            function initConfirmForms() {
                const modalElement = document.getElementById('appConfirmModal');
                if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) {
                    return;
                }

                const modalTitle = document.getElementById('appConfirmModalTitle');
                const modalMessage = document.getElementById('appConfirmModalMessage');
                const modalOk = document.getElementById('appConfirmModalOk');
                const confirmModal = new bootstrap.Modal(modalElement);

                document.querySelectorAll('form[data-confirm-message]').forEach(function (form) {
                    if (form.dataset.confirmAttached === '1') {
                        return;
                    }

                    form.dataset.confirmAttached = '1';
                    form.addEventListener('submit', function (event) {
                        if (form.dataset.confirmBypass === '1') {
                            form.dataset.confirmBypass = '0';
                            return;
                        }

                        event.preventDefault();
                        pendingForm = form;

                        modalTitle.textContent = form.getAttribute('data-confirm-title') || 'Please Confirm';
                        modalMessage.textContent = form.getAttribute('data-confirm-message') || 'Are you sure you want to continue?';
                        confirmModal.show();
                    });
                });

                modalOk.onclick = function () {
                    if (!pendingForm) {
                        return;
                    }
                    pendingForm.dataset.confirmBypass = '1';
                    confirmModal.hide();
                    pendingForm.requestSubmit();
                    pendingForm = null;
                };

                modalElement.addEventListener('hidden.bs.modal', function () {
                    pendingForm = null;
                });
            }

            document.addEventListener('DOMContentLoaded', initConfirmForms);
        })();
    </script>
</body>
</html>
