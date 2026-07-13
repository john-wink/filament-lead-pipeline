<div class="flex items-center gap-2">
    <span class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ $activity->type->getLabel() }}</span>
    <span class="text-xs text-gray-400 dark:text-gray-500">{{ $activity->created_at->diffForHumans() }}</span>
</div>
