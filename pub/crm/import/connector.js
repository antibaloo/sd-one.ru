function sendMessage(params) {
    auth = btoa(params.User+':'+params.Passwd);
    request = new HttpRequest();
    json_payload = JSON.stringify({subject:params.Subject, message:params.Message});
    request.addHeader('Authorization:Basic '+auth);
    response = request.post(params.Url, json_payload);
    if (response !== "OK") {
        throw response
    }
}
function validateParams(params) {
    if (typeof params.User!== 'string' || params.User.trim() === '') {
        throw 'Field "User" cannot be empty';
    }
    if (typeof params.Passwd!== 'string' || params.Passwd.trim() === '') {
        throw 'Field "Passwd" cannot be empty';
    }
    if (typeof params.Url!== 'string' || params.Url.trim() === '') {
        throw 'Field "Url" cannot be empty';
    }
    if (!/^(http|https):\/\/.+/.test(params.Url)) {
        throw 'Field "Url" must contain a schema';
    }
}

try {
    var params = JSON.parse(value);
    validateParams(params);
    sendMessage(params);
    return 'OK';
}
catch (err) {
    Zabbix.log(4, '[ bitrix Webhook ] bitrix notification failed : ' + err);
    throw 'bitrix notification failed : ' + err;
}