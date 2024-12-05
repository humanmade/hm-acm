function handler(event) {
    var request = event.request;
    var headers = request.headers;

    // Check if the Host header exists
    if (headers.host) {
        // Copy the Host header value to the new x-original-host header (lowercase)
        headers['x-original-host'] = {
            value: headers.host.value
        };
    }

    return request;
}
