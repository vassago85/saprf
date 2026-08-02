<x-error-page
    status="503"
    title="Under maintenance"
    :message="$exception?->getMessage() ?: 'The site is temporarily down for maintenance. Please check back shortly.'" />
