<?php

function backButton(User $user, DatabaseManager $db, int|string|null $parent_btn_id = null): void
{

    /**
     * TODO:
     *  If the progress has its own level, when user is at level 1
     *  The button should redirect the user to parent level, but
     *  if the progress doesn't have its own level, pushing the
     *  back button at level 1 should only clear the progress.
     */

    $progress = $user->getProgress();
    $current_btn = $parent_btn_id ? getStructuredButton($parent_btn_id, $user->isAdmin(), $db) : $user->getButton();

    if ($progress) {

        if (array_key_exists('data', $progress)) $progress_data = &$progress['data'];
        else $progress_data = &$progress;
        $current_progress = &$progress_data[array_key_first($progress_data)];

        if (sizeof($current_progress) > 1) {
            // Delete the last level
            array_pop($current_progress);
            // Clear the current last level
            $current_progress[array_key_last($current_progress)] = null;
            normalButtonHandler($user->setProgress($progress), $current_btn, $db);
        }
    }

    // If user has no progress (Or is at level 1 of a progress) redirect back to the parent level.
    $parent_btn = getStructuredButton($current_btn->getBelongTo(), $user->isAdmin(), $db);

    normalButtonHandler(user: $user->setProgress(null), pressed_button: $parent_btn, db: $db);
}

function cancelButton(User $user, DatabaseManager $db, int|string|null $parent_btn_id = null): void
{
    backButton($user->setProgress(null), $db, $parent_btn_id);
}
