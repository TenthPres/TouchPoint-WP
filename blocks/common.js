export function generateUniqueId() {
    // generate a string of incrementing numbers based on a static variable
    if (typeof generateUniqueId.counter == 'undefined') {
        generateUniqueId.counter = 0;
    }

    return ++generateUniqueId.counter;
}

