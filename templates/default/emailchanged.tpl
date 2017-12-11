{% autoescape false %}
{% block subject %}
Email address changed on {{ sitename }}.
{% endblock %}

{% block body %}
Hi {{ user.getRealName() }},

This email is to let you know that your email address on {{ sitename }} has been changed and it is now {{ user.getEmail() }}

If you did not request this password change, please get in touch immediately.

{% include 'footer.txt' %}
{% endblock %}
{% endautoescape %}
