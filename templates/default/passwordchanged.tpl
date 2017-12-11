{% autoescape false %}
{% block subject %}
Password changed on {{ sitename }}.
{% endblock %}

{% block body %}
Hi {{ user.getRealName() }},

This email is to let you know that your password on {{ sitename }} has been changed.

If you did not request this password change, please get in touch immediately.

{% include 'footer.txt' %}
{% endblock %}
{% endautoescape %}
