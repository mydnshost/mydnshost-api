{% block subject %}
Forgotten password on {{ sitename }}.
{% endblock %}

{% block body %}
Hi {{ user.getRealName() }},

Someone (hopefully you) has submitted a password reset request on {{ sitename }}.

You can complete the change and set a new password by visiting: {{ siteurl }}forgotpassword/confirm/{{ user.getID() }}/{{ user.getVerifyCode() }}

Please note this URL is only valid once, and only for a short period of time.

If you did not request this password reset, feel free to disregard this mail, nothing has been changed on your account.

{% include 'footer.txt' %}
{% endblock %}
