<?php

/**
 * Searches for a specific value in a nested array.
 *
 * @param string|array $needle The key, or keys, to search for in the array.
 * @param array $haystack The array to search through.
 *
 * @return mixed The found value if it exists, otherwise null.
 */
function searchArray(string|array $needle, array $haystack): mixed
{
    $result = null;
    if(is_array($needle)){
        foreach ($needle as $n) {
            $result = quickSearch($n, $haystack);
            if ($result !== null) {
                break;
            }
        }
    }
    else{
        $result = quickSearch($needle, $haystack);
    }
    return $result;
}

function searchArrayAsArray(string|array $needle, array $haystack): ?array
{
    $result = searchArray($needle, $haystack);

    return is_array($result) ? $result : null;
}

function quickSearch(string $needle, array $haystack): mixed
{
    foreach ($haystack as $key => $value) {
        if ($key === $needle && !empty($haystack[$key])) {
            return $haystack[$key];
        } elseif (is_array($haystack[$key])) {
            $result = quickSearch($needle, $haystack[$key]);
            if ($result !== null) {
                return $result;
            }
        }
    }
    return null;
}
