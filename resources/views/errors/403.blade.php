<x-error-page
    status="403"
    title="Access denied"
    :message="$exception?->getMessage() ?: 'You don\'t have permission to view this page.'" />
