@props([
    'proof',
    'label' => 'View receipt',
    'title' => 'Submitted receipt',
    'downloadLabel' => 'Download receipt',
    'primary' => false,
    'inline' => false,
    'showInlineDownload' => true,
])
<x-document-viewer
    :id="'payment-proof-'.$proof->id"
    :view-url="route('finance.payment-evidence.view', $proof)"
    :download-url="route('finance.payment-evidence.download', $proof)"
    :title="$title"
    :description="$proof->original_name.' · '.str($proof->state)->headline()"
    :trigger-label="$label"
    :download-label="$downloadLabel"
    :primary="$primary"
    :inline="$inline"
    :show-inline-download="$showInlineDownload"
/>
