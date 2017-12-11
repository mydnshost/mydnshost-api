{% autoescape false %}
{% block subject %}
New account created on {{ sitename }}.
{% endblock %}

{% block body %}
Hi {{ user.getRealName() }},

A new account has been created for you on {{ sitename }}.

Before you can use this account, you must verify your email address by visiting: {{ siteurl }}register/verify/{{ user.getID() }}/{{ user.getVerifyCode() }}

If you did not request this account, feel free to disregard this mail, you will recieve no further communication from us.

{% include 'footer.txt' %}
{% endblock %}
{% endautoescape %}
