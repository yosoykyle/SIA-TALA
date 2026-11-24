@php
    $campusAddress = trim((string) config('institution.address', '162 R. Magsaysay Ave, San Pedro, Laguna, Philippines'));
@endphp

<div class="tala-privacy-notice-content">
    <p class="tala-info-content__lead">
        This Data Privacy Notice is issued in compliance with <strong>Republic Act No. 10173</strong>, also known as the <strong>Data Privacy Act of 2012</strong>, and its Implementing Rules and Regulations (IRR).
    </p>

    <section class="tala-info-content__section" aria-labelledby="tala-privacy-controller-heading">
        <h3 class="h5 tala-info-content__heading" id="tala-privacy-controller-heading">Personal Information Controller</h3>
        <p>
            <strong>Servitech Institute Asia Inc.</strong>@if ($campusAddress !== '') ({{ $campusAddress }})@endif acts as the Personal Information Controller for all personal data collected and processed through TALA.
        </p>
    </section>

    <section class="tala-info-content__section" aria-labelledby="tala-privacy-purpose-heading">
        <h3 class="h5 tala-info-content__heading" id="tala-privacy-purpose-heading">Purpose &amp; Lawful Basis (RA 10173, Sec. 12)</h3>
        <p>
            Your personal data is collected and processed strictly for legitimate educational and institutional administration under Section 12 of Republic Act No. 10173:
        </p>
        <ul class="tala-info-content__list">
            <li>Applicant account registration, duplicate-account prevention, and email ownership verification.</li>
            <li>Admission evaluation, document requirement verification, and official enrollment processing.</li>
            <li>Academic and Registrar administration, student record maintenance, and account recovery.</li>
            <li>Account security, role-based workspace authorization, and institutional accountability.</li>
        </ul>
    </section>

    <section class="tala-info-content__section" aria-labelledby="tala-privacy-collected-heading">
        <h3 class="h5 tala-info-content__heading" id="tala-privacy-collected-heading">Personal Data Collected</h3>
        <p>
            During account registration and sign-in, TALA collects your email address, a securely hashed password representation, your Privacy Notice acknowledgment, and security and email-verification audit records. Once signed in, TALA processes the admission, academic, and enrollment information you submit for your official school journey. Creating an Applicant account does not automatically create an Admission Application or Student record, and TALA never displays your password in plain text.
        </p>
        <p>
            Application updates, requirements and corrections are available only through your signed-in Applicant account. Access to private application records and evidence is checked against the account and the authorized school role.
        </p>
    </section>

    <section class="tala-info-content__section" aria-labelledby="tala-privacy-security-heading">
        <h3 class="h5 tala-info-content__heading" id="tala-privacy-security-heading">Security &amp; Access Safeguards (RA 10173, Sec. 20)</h3>
        <p>
            In accordance with Section 20 of Republic Act No. 10173, your records are protected by organizational, physical, and technical safeguards, including role-based access controls, encrypted password storage, multi-factor authentication for staff workspaces, and security audit logs. Authorized school personnel access records only for permitted official duties.
        </p>
    </section>

    <section class="tala-info-content__section" aria-labelledby="tala-privacy-rights-heading">
        <h3 class="h5 tala-info-content__heading" id="tala-privacy-rights-heading">Your Rights as a Data Subject (RA 10173, Sec. 16)</h3>
        <p>
            Under Section 16 of Republic Act No. 10173, you are entitled to the following data privacy rights:
        </p>
        <ul class="tala-info-content__list">
            <li><strong>Right to be informed</strong> of how your personal data is collected, processed, stored, and protected.</li>
            <li><strong>Right to access</strong> your personal and academic records through authorized school channels.</li>
            <li><strong>Right to object</strong> to unauthorized processing and to withhold or withdraw consent where applicable.</li>
            <li><strong>Right to rectify</strong> inaccurate, incomplete, or outdated personal data.</li>
            <li><strong>Right to erasure or blocking</strong> of personal data when warranted under the Data Privacy Act of 2012 and applicable institutional regulations.</li>
            <li><strong>Right to lodge a complaint</strong> before the <strong>National Privacy Commission (NPC)</strong> and to seek indemnification for damages sustained from unlawful or unauthorized processing.</li>
        </ul>
    </section>

    <section class="tala-info-content__section" aria-labelledby="tala-privacy-retention-heading">
        <h3 class="h5 tala-info-content__heading" id="tala-privacy-retention-heading">Retention &amp; Disposal</h3>
        <p>
            Account, admission, and academic records are retained in accordance with institutional academic record policies and applicable CHED and TESDA regulations. When retention periods expire and records are no longer required by law or school policy, they are lawfully disposed of so that personal data cannot be reconstructed or retrieved.
        </p>
    </section>

    <section class="tala-info-content__section" aria-labelledby="tala-privacy-dpo-heading">
        <h3 class="h5 tala-info-content__heading" id="tala-privacy-dpo-heading">Data Protection Officer &amp; Support Contact</h3>
        <p class="mb-0">
            For data privacy inquiries, record access or correction requests, or assistance exercising your rights under Republic Act No. 10173, contact the <strong>Servitech Institute Asia Inc.</strong> Data Protection Officer or the Registrar and Admissions Office through our official school support channels.
        </p>
    </section>
</div>
